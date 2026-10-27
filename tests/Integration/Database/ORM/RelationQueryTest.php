<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\ORM;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\ORM\Collection;
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Relations\BelongsTo;
use Glueful\Database\ORM\Relations\BelongsToMany;
use Glueful\Database\ORM\Relations\HasMany;
use Glueful\Database\ORM\Relations\HasManyThrough;
use Glueful\Database\ORM\Relations\Pivot;
use Glueful\Framework;
use Glueful\Http\Resources\ModelResource;
use PHPUnit\Framework\TestCase;

class RqUser extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name'];
    public bool $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(RqPost::class, 'user_id');
    }

    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(RqComment::class, RqPost::class, 'user_id', 'post_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RqRole::class, 'role_user', 'user_id', 'role_id')
            ->withPivot('granted_by');
    }
}

class RqPost extends Model
{
    protected string $table = 'posts';
    protected array $fillable = ['user_id', 'title', 'published'];
    public bool $timestamps = false;

    public function comments(): HasMany
    {
        return $this->hasMany(RqComment::class, 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(RqUser::class, 'user_id');
    }
}

class RqComment extends Model
{
    protected string $table = 'comments';
    protected array $fillable = ['post_id', 'body'];
    public bool $timestamps = false;
}

class RqRole extends Model
{
    protected string $table = 'roles';
    protected array $fillable = ['name'];
    public bool $timestamps = false;
}

/**
 * Relation query behaviour against a real SQLite connection: belongsToMany eager
 * loading and pivot writes, withCount/loadCount, whereHas constraints, and paginate().
 */
final class RelationQueryTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFramework();
        $this->setUpSchema();
    }

    protected function tearDown(): void
    {
        Model::resetLazyLoadingState();
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- belongsToMany

    public function testBelongsToManyEagerLoadJoinsPivotAndAttachesPivotAttributes(): void
    {
        $users = RqUser::query($this->context)->with('roles')->orderBy('id')->get();

        $alice = $users[0];
        $bob = $users[1];
        $carol = $users[2];

        self::assertSame(['admin', 'editor'], $this->names($alice->getRelation('roles')));
        self::assertSame(['viewer'], $this->names($bob->getRelation('roles')));
        self::assertSame([], $this->names($carol->getRelation('roles')));

        $pivot = $alice->getRelation('roles')[0]->getRelation('pivot');
        self::assertInstanceOf(Pivot::class, $pivot);
        self::assertSame('root', $pivot->granted_by);
        self::assertSame(1, (int) $pivot->user_id);

        // pivot_* aliases must not leak into the related model's own attributes
        $roleAttributes = $alice->getRelation('roles')[0]->getAttributes();
        self::assertArrayNotHasKey('pivot_user_id', $roleAttributes);
        self::assertArrayNotHasKey('pivot_granted_by', $roleAttributes);
    }

    public function testBelongsToManyEagerLoadWithConstraintClosure(): void
    {
        $users = RqUser::query($this->context)
            ->with(['roles' => fn ($q) => $q->where('roles.name', '=', 'editor')])
            ->orderBy('id')
            ->get();

        self::assertSame(['editor'], $this->names($users[0]->getRelation('roles')));
        self::assertSame([], $this->names($users[1]->getRelation('roles')));
    }

    public function testNestedEagerLoadMatchesEveryParentNotJustTheFirst(): void
    {
        $users = RqUser::query($this->context)->with('posts.comments')->orderBy('id')->get();

        $bobPost = $users[1]->getRelation('posts')[0];
        self::assertSame(['meh'], $this->column($bobPost->getRelation('comments'), 'body'));

        $posts = RqPost::query($this->context)->with('author.roles')->orderBy('id')->get();
        self::assertSame(['viewer'], $this->names($posts[2]->getRelation('author')->getRelation('roles')));
    }

    public function testHasManyThroughEagerLoadCountAndHas(): void
    {
        $users = RqUser::query($this->context)->with('comments')->withCount('comments')->orderBy('id')->get();

        self::assertSame(['nice'], $this->column($users[0]->getRelation('comments'), 'body'));
        self::assertSame(['meh'], $this->column($users[1]->getRelation('comments'), 'body'));
        self::assertSame([1, 1, 0], $this->column($users, 'comments_count'));

        self::assertSame(['Alice'], $this->names(
            RqUser::query($this->context)->whereHas('comments', fn ($q) => $q->where('body', '=', 'nice'))->get()
        ));
    }

    public function testDetachWithIdsRemovesOnlyThosePivotRows(): void
    {
        $alice = $this->user(1);

        self::assertSame(1, $alice->roles()->detach([1]));

        self::assertSame([2], $this->pivotRoleIds(1));
        self::assertSame([3], $this->pivotRoleIds(2), 'other parents untouched');
    }

    public function testDetachSingleIdAndModel(): void
    {
        $alice = $this->user(1);

        self::assertSame(1, $alice->roles()->detach(2));
        self::assertSame([1], $this->pivotRoleIds(1));

        $admin = RqRole::find($this->context, 1);
        self::assertNotNull($admin);
        self::assertSame(1, $alice->roles()->detach($admin));
        self::assertSame([], $this->pivotRoleIds(1));
    }

    public function testDetachWithoutArgumentsRemovesAllRowsForParentOnly(): void
    {
        self::assertSame(2, $this->user(1)->roles()->detach());

        self::assertSame([], $this->pivotRoleIds(1));
        self::assertSame([3], $this->pivotRoleIds(2));
    }

    public function testSyncResultsInExactlyTheGivenSetAndReportsChanges(): void
    {
        $changes = $this->user(1)->roles()->sync([2, 3]);

        self::assertSame([2, 3], $this->pivotRoleIds(1));
        self::assertSame([3], $changes['attached']);
        self::assertSame([1], array_map('intval', $changes['detached']));
        self::assertSame([], $changes['updated']);
        self::assertSame([3], $this->pivotRoleIds(2));
    }

    public function testSyncUpdatesPivotAttributesOfExistingRows(): void
    {
        $changes = $this->user(1)->roles()->sync([1 => ['granted_by' => 'ops'], 2]);

        self::assertSame([1, 2], $this->pivotRoleIds(1));
        self::assertSame([], $changes['attached']);
        self::assertSame([], $changes['detached']);
        self::assertSame([1], $changes['updated']);
        self::assertSame('ops', $this->pdo()->query(
            'SELECT granted_by FROM role_user WHERE user_id = 1 AND role_id = 1'
        )->fetchColumn());
    }

    public function testSyncWithEmptyArrayDetachesEverything(): void
    {
        $changes = $this->user(1)->roles()->sync([]);

        self::assertSame([], $this->pivotRoleIds(1));
        self::assertCount(2, $changes['detached']);
    }

    public function testToggleAttachesMissingAndDetachesPresent(): void
    {
        $changes = $this->user(1)->roles()->toggle([1, 3]);

        self::assertSame([2, 3], $this->pivotRoleIds(1));
        self::assertSame([3], $changes['attached']);
        self::assertSame([1], $changes['detached']);
    }

    // ---------------------------------------------------------------- withCount

    public function testWithCountAddsCountAttributesForHasManyAndBelongsToMany(): void
    {
        $users = RqUser::query($this->context)->withCount('posts', 'roles')->orderBy('id')->get();

        self::assertSame([2, 1, 0], $this->column($users, 'posts_count'));
        self::assertSame([2, 1, 0], $this->column($users, 'roles_count'));
        self::assertSame('Alice', $users[0]->name);
        self::assertFalse($users[0]->isDirty(), 'counts are not dirty attributes');
    }

    public function testWithCountAppliesConstraintClosure(): void
    {
        $users = RqUser::query($this->context)
            ->withCount(['posts' => fn ($q) => $q->where('published', '=', 1)])
            ->orderBy('id')
            ->get();

        self::assertSame([1, 0, 0], $this->column($users, 'posts_count'));
    }

    public function testWithCountForBelongsTo(): void
    {
        $posts = RqPost::query($this->context)->withCount('author')->orderBy('id')->get();

        self::assertSame([1, 1, 1], $this->column($posts, 'author_count'));
    }

    public function testWithCountModelsCanBeSaved(): void
    {
        $alice = RqUser::query($this->context)->withCount('posts')->where('id', '=', 1)->first();
        self::assertNotNull($alice);

        $alice->name = 'Alicia';
        self::assertTrue($alice->save());
        self::assertSame('Alicia', $this->pdo()->query('SELECT name FROM users WHERE id = 1')->fetchColumn());
    }

    // ---------------------------------------------------------------- loadCount

    public function testLoadCountIsNotPersistedOnSave(): void
    {
        $alice = $this->user(1);
        $alice->loadCount('posts');

        self::assertSame(2, $alice->posts_count);
        self::assertFalse($alice->isDirty());

        $alice->name = 'Alicia';
        self::assertTrue($alice->save());
        self::assertSame('Alicia', $this->pdo()->query('SELECT name FROM users WHERE id = 1')->fetchColumn());
    }

    public function testLoadCountWithConstraintAndBelongsToMany(): void
    {
        $alice = $this->user(1);
        $alice->loadCount(['posts' => fn ($q) => $q->where('published', '=', 1)]);
        $alice->loadCount('roles');

        self::assertSame(1, $alice->posts_count);
        self::assertSame(2, $alice->roles_count);
    }

    // ---------------------------------------------------------------- whereHas & co

    public function testWhereHasAppliesConstraintClosure(): void
    {
        $names = $this->names(RqUser::query($this->context)
            ->whereHas('posts', fn ($q) => $q->where('title', '=', 'Bob draft'))
            ->get());

        self::assertSame(['Bob'], $names);
    }

    public function testHasAndDoesntHave(): void
    {
        self::assertSame(['Alice', 'Bob'], $this->names(
            RqUser::query($this->context)->has('posts')->orderBy('id')->get()
        ));
        self::assertSame(['Alice'], $this->names(
            RqUser::query($this->context)->has('posts', '>=', 2)->get()
        ));
        self::assertSame(['Carol'], $this->names(
            RqUser::query($this->context)->doesntHave('posts')->get()
        ));
    }

    public function testWhereHasOnBelongsToManyAndBelongsTo(): void
    {
        self::assertSame(['Alice'], $this->names(
            RqUser::query($this->context)->whereHas('roles', fn ($q) => $q->where('roles.name', '=', 'admin'))->get()
        ));
        self::assertSame(['Carol'], $this->names(
            RqUser::query($this->context)->doesntHave('roles')->get()
        ));

        $titles = RqPost::query($this->context)
            ->whereHas('author', fn ($q) => $q->where('name', '=', 'Bob'))
            ->get();
        self::assertSame(['Bob draft'], $this->column($titles, 'title'));
    }

    public function testWhereDoesntHaveAndOrWhereHasApplyClosures(): void
    {
        self::assertSame(['Bob', 'Carol'], $this->names(
            RqUser::query($this->context)
                ->whereDoesntHave('posts', fn ($q) => $q->where('published', '=', 1))
                ->orderBy('id')
                ->get()
        ));
        self::assertSame(['Bob', 'Carol'], $this->names(
            RqUser::query($this->context)
                ->where('name', '=', 'Carol')
                ->orWhereHas('posts', fn ($q) => $q->where('title', '=', 'Bob draft'))
                ->orderBy('id')
                ->get()
        ));
    }

    public function testNestedHas(): void
    {
        self::assertSame(['Alice'], $this->names(
            RqUser::query($this->context)->whereHas('posts.comments', fn ($q) => $q->where('body', '=', 'nice'))->get()
        ));
    }

    // ---------------------------------------------------------------- paginate

    public function testPaginateReturnsHydratedModelsWithEagerLoadsAndCounts(): void
    {
        $page = RqUser::query($this->context)
            ->with('posts')
            ->withCount('roles')
            ->orderBy('id')
            ->paginate(1, 2);

        self::assertInstanceOf(Collection::class, $page['data']);
        self::assertCount(2, $page['data']);
        self::assertInstanceOf(RqUser::class, $page['data'][0]);
        self::assertTrue($page['data'][0]->relationLoaded('posts'));
        self::assertCount(2, $page['data'][0]->getRelation('posts'));
        self::assertSame(2, $page['data'][0]->roles_count);

        self::assertSame(3, $page['total']);
        self::assertSame(1, $page['current_page']);
        self::assertSame(2, $page['per_page']);
        self::assertSame(2, $page['last_page']);
        self::assertTrue($page['has_more']);
        self::assertSame(1, $page['from']);
        self::assertSame(2, $page['to']);
        self::assertArrayHasKey('execution_time_ms', $page);

        // The documented resource flow consumes the model page unchanged.
        $payload = ModelResource::collection($page['data'])->withPaginationFrom($page)->toArray();
        self::assertSame('Alice', $payload['data'][0]['name']);
        self::assertSame(2, $payload['data'][0]['roles_count']);
        self::assertCount(2, $payload['data'][0]['posts']);
    }

    // ---------------------------------------------------------------- helpers

    private function user(int $id): RqUser
    {
        $user = RqUser::find($this->context, $id);
        self::assertInstanceOf(RqUser::class, $user);

        return $user;
    }

    /**
     * @return list<int>
     */
    private function pivotRoleIds(int $userId): array
    {
        $stmt = $this->pdo()->query("SELECT role_id FROM role_user WHERE user_id = {$userId} ORDER BY role_id");

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param iterable<Model> $models
     * @return list<mixed>
     */
    private function names(iterable $models): array
    {
        return $this->column($models, 'name');
    }

    /**
     * @param iterable<Model> $models
     * @return list<mixed>
     */
    private function column(iterable $models, string $key): array
    {
        $out = [];
        foreach ($models as $model) {
            $out[] = $model->{$key};
        }

        return $out;
    }

    private function pdo(): \PDO
    {
        return $this->app->getContainer()->get('database')->getPDO();
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-relation-query-' . uniqid();
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents(
            $configPath . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $configPath . '/database.php',
            "<?php\nreturn ["
            . "'engine' => 'sqlite', "
            . "'sqlite' => ['primary' => ':memory:'], "
            . "'pooling' => ['enabled' => false]"
            . "];\n"
        );
        file_put_contents(
            $configPath . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', "
            . "'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function setUpSchema(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, published INTEGER)');
        $pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, body TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER, granted_by TEXT)');

        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob'), (3, 'Carol')");
        $pdo->exec("INSERT INTO posts (id, user_id, title, published) VALUES "
            . "(1, 1, 'Hello', 1), (2, 1, 'Alice draft', 0), (3, 2, 'Bob draft', 0)");
        $pdo->exec("INSERT INTO comments (id, post_id, body) VALUES (1, 1, 'nice'), (2, 3, 'meh')");
        $pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin'), (2, 'editor'), (3, 'viewer')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id, granted_by) VALUES "
            . "(1, 1, 'root'), (1, 2, 'root'), (2, 3, 'alice')");
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
