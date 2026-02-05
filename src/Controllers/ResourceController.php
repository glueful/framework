<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Auth\PasswordHasher;
use Glueful\Repository\RepositoryFactory;
use Glueful\Constants\ErrorCodes;
use Glueful\Controllers\Traits\BulkOperationsTrait;
use Glueful\Helpers\RequestHelper;
use Symfony\Component\HttpFoundation\Request;

/**
 * ResourceController - RESTful CRUD API Controller
 *
 * Provides complete CRUD (Create, Read, Update, Delete) operations for any
 * resource table with performance-first design and optional security features.
 *
 * CRUD Operations:
 * - GET    /data/{table}      -> index() method (Read multiple/paginated)
 * - GET    /data/{table}/{id} -> show() method (Read single record)
 * - POST   /data/{table}      -> store() method (Create new record)
 * - PUT    /data/{table}/{id} -> update() method (Update existing record)
 * - DELETE /data/{table}/{id} -> destroy() method (Delete record)
 *
 * Performance-First Design:
 * - Minimal overhead by default
 * - Security features are opt-in via configuration
 * - Built-in rate limiting and caching support
 * - Configurable limits and restrictions
 *
 * Available Security Features (opt-in):
 * - TableAccessControlTrait: Restrict sensitive tables
 * - FieldLevelPermissionsTrait: Hide sensitive fields
 * - BulkOperationsTrait: Secure bulk operations
 * - QueryRestrictionsTrait: Limit query parameters
 * - Ownership validation for user-specific data
 *
 * @package Glueful\Controllers
 */
class ResourceController extends BaseController
{
    use BulkOperationsTrait;

    /**
     * Security feature toggles - can be overridden in child classes
     */
    protected bool $enableTableAccessControl = false;
    protected bool $enableFieldPermissions = false;
    protected bool $enableBulkOperations = false;
    protected bool $enableQueryRestrictions = false;
    protected bool $enableOwnershipValidation = true; // Keep this as reasonable default

    public function __construct(
        \Glueful\Bootstrap\ApplicationContext $context,
        ?RepositoryFactory $repositoryFactory = null
    ) {
        // Call parent constructor which handles auth initialization
        parent::__construct($context, $repositoryFactory);

        // Load configuration-based feature toggles
        $this->loadSecurityConfiguration();
    }

    /**
     * Load security configuration from config files
     */
    protected function loadSecurityConfiguration(): void
    {
        // Allow configuration to override defaults
        $config = config($this->getContext(), 'resource.security', []);

        $this->enableTableAccessControl = $config['table_access_control'] ?? $this->enableTableAccessControl;
        $this->enableFieldPermissions = $config['field_permissions'] ?? $this->enableFieldPermissions;
        $this->enableBulkOperations = $config['bulk_operations'] ?? $this->enableBulkOperations;
        $this->enableQueryRestrictions = $config['query_restrictions'] ?? $this->enableQueryRestrictions;
        $this->enableOwnershipValidation = $config['ownership_validation'] ?? $this->enableOwnershipValidation;
    }

    /**
     * Get resource list with pagination
     *
     * @route GET /data/{table}
     */
    public function index(Request $request): Response
    {
        $table = $request->attributes->get('table', '');
        $queryParams = $request->query->all();

        // Apply optional table access control
        $this->applyTableAccessControl($table);

        // Check specific table permission first
        if (!$this->can("resource.{$table}.read")) {
            // Fall back to generic read permission
            $this->requirePermission('resource.read');
        }

        // Parse query parameters for repository
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $perPage = min(100, max(1, (int)($queryParams['per_page'] ?? 25)));
        $sort = $queryParams['sort'] ?? 'id'; // Default sort by ID
        $order = strtolower($queryParams['order'] ?? 'desc');
        $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
        $fields = $this->parseFields($queryParams['fields'] ?? '');

        // Apply optional query parameter restrictions
        $queryParams = $this->applyQueryRestrictions($queryParams, $table);

        // Build conditions and order
        $conditions = $this->parseConditions($queryParams);
        $orderBy = [$sort => $order];

        // Get repository and paginate results with caching
        $repository = $this->repositoryFactory->getRepository($table);

        // Cache read operations by user and table
        $result = $this->cacheByPermission(
            "resource:{$table}:list",
            fn() => $repository->paginate($page, $perPage, $conditions, $orderBy, $fields),
            600 // 10 minutes
        );

        // Apply optional field-level permissions
        $result = $this->applyFieldPermissions($result, $table, 'read');

        $data = $result['data'] ?? [];
        $meta = $result;
        unset($meta['data']); // Remove data from meta
        return Response::successWithMeta($data, $meta, 'Resource list retrieved successfully');
    }

    /**
     * Get single resource by UUID
     *
     * @route GET /data/{table}/{uuid}
     */
    public function show(Request $request): Response
    {
        $table = $request->attributes->get('table', '');
        $uuid = $request->attributes->get('uuid', '');

        // Apply optional table access control
        $this->applyTableAccessControl($table);

        // Check specific table permission first
        if (!$this->can("resource.{$table}.read")) {
            // Fall back to generic read permission
            $this->requirePermission('resource.read');
        }

        // Get repository and find single record with caching
        $repository = $this->repositoryFactory->getRepository($table);

        // Cache single resource reads
        $result = $this->cacheByPermission(
            "resource:{$table}:read:{$uuid}",
            fn() => $repository->find($uuid),
            300 // 5 minutes
        );

        if ($result === null) {
            return Response::error('Record not found', ErrorCodes::NOT_FOUND);
        }

        // Apply optional ownership validation
        $this->applyOwnershipValidation($table, $uuid, $result);

        // Apply optional field-level permissions
        $result = $this->applyFieldPermissions($result, $table, 'read');

        return Response::success($result);
    }

    /**
     * Create new resource
     *
     * @route POST /data/{table}
     */
    public function store(Request $request): Response
    {
        $table = $request->attributes->get('table', '');
        $postData = RequestHelper::getRequestData();

        // Apply optional table access control
        $this->applyTableAccessControl($table);

        // Check specific table permission first
        if (!$this->can("resource.{$table}.create")) {
            // Fall back to generic create permission
            $this->requirePermission('resource.create');
        }

        if ($postData === []) {
            return Response::error('No data provided', ErrorCodes::BAD_REQUEST);
        }

        // check if postData contains 'password' and hash it
        $passwordHasher = new PasswordHasher();
        if (isset($postData['password'])) {
            $postData['password'] = $passwordHasher->hash($postData['password']);
        }

        // Get repository and create record
        $repository = $this->repositoryFactory->getRepository($table);
        $uuid = $repository->create($postData);

        // Invalidate cache after creation
        $this->invalidateTableCache($table);

        $result = [
            'uuid' => $uuid,
            'success' => true,
            'message' => 'Record created successfully'
        ];

        return Response::success($result);
    }

    /**
     * Update existing resource
     *
     * @route PUT /data/{table}/{uuid}
     */
    public function update(Request $request): Response
    {
        $table = $request->attributes->get('table', '');
        $uuid = $request->attributes->get('uuid', '');
        $putData = RequestHelper::getPutData();

        // Apply optional table access control
        $this->applyTableAccessControl($table);

        // Check specific table permission first
        if (!$this->can("resource.{$table}.update")) {
            // Fall back to generic update permission
            $this->requirePermission('resource.update');
        }

        // Get repository and check if record exists
        $repository = $this->repositoryFactory->getRepository($table);
        $existing = $repository->find($uuid);

        if ($existing === null) {
            return Response::error('Record not found', ErrorCodes::NOT_FOUND);
        }

        // Apply optional ownership validation
        $this->applyOwnershipValidation($table, $uuid, $existing);

        // Extract data from nested structure if present (for compatibility)
        $updateData = $putData['data'] ?? $putData;
        unset($updateData['uuid']); // Remove UUID from update data

        // check if putData contains 'password' and hash it
        $passwordHasher = new PasswordHasher();
        if (isset($updateData['password'])) {
            $updateData['password'] = $passwordHasher->hash($updateData['password']);
        }

        $success = $repository->update($uuid, $updateData);

        if (!$success) {
            return Response::error('Record not found or update failed', ErrorCodes::NOT_FOUND);
        }

        // Invalidate cache after update
        $this->invalidateTableCache($table, $uuid);

        $result = [
            'affected' => 1,
            'success' => true,
            'message' => 'Record updated successfully'
        ];

        return Response::success($result);
    }

    /**
     * Delete resource
     *
     * @route DELETE /data/{table}/{uuid}
     */
    public function destroy(Request $request): Response
    {
        $table = $request->attributes->get('table', '');
        $uuid = $request->attributes->get('uuid', '');

        // Apply optional table access control
        $this->applyTableAccessControl($table);

        // Check specific table permission first
        if (!$this->can("resource.{$table}.delete")) {
            // Fall back to generic delete permission
            $this->requirePermission('resource.delete');
        }

        // Get repository and check if record exists
        $repository = $this->repositoryFactory->getRepository($table);
        $existing = $repository->find($uuid);

        if ($existing === null) {
            return Response::error('Record not found', ErrorCodes::NOT_FOUND);
        }

        // Apply optional ownership validation
        $this->applyOwnershipValidation($table, $uuid, $existing);

        $success = $repository->delete($uuid);

        if (!$success) {
            return Response::error('Record not found or delete failed', ErrorCodes::NOT_FOUND);
        }

        // Invalidate cache after deletion
        $this->invalidateTableCache($table, $uuid);

        $result = [
            'affected' => 1,
            'success' => true,
            'message' => 'Record deleted successfully'
        ];

        return Response::success($result, 'Resource deleted successfully');
    }


    /**
     * Invalidate cache for a resource table
     */
    protected function invalidateTableCache(string $table, ?string $uuid = null): void
    {
        // Build cache tags to invalidate
        $tags = ["repository:{$table}", "resource:{$table}"];

        // If UUID provided, also add specific resource cache tag
        if ($uuid !== null) {
            $tags[] = "resource:{$table}:{$uuid}";
        }

        // Invalidate cache using base controller method
        $this->invalidateCache($tags);
    }

    // =================================================================
    // Security Feature Application Methods (Can be overridden by traits)
    // =================================================================

    /**
     * Apply table access control if enabled
     */
    protected function applyTableAccessControl(string $table): void
    {
        // Default: no restrictions
        // Override via TableAccessControlTrait
    }

    /**
     * Apply field-level permissions if enabled
     * @param mixed $data
     * @return mixed
     */
    protected function applyFieldPermissions($data, string $table, string $operation)
    {
        // Default: no field filtering
        // Override via FieldLevelPermissionsTrait
        return $data;
    }

    /**
     * Apply query parameter restrictions if enabled
     */
    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    protected function applyQueryRestrictions(array $queryParams, string $table): array
    {
        // Default: no query restrictions
        // Override via QueryRestrictionsTrait
        return $queryParams;
    }

    /**
     * Apply ownership validation if enabled
     */
    /**
     * @param array<string, mixed> $record
     */
    protected function applyOwnershipValidation(string $table, string $uuid, array $record): void
    {
        if (!$this->enableOwnershipValidation) {
            return;
        }

        // Simple ownership check for known tables
        if (!in_array($table, ['profiles', 'blobs'], true)) {
            return; // Not an owned resource
        }

        if ($this->currentUser === null) {
            $this->requirePermission('admin.access');
            return;
        }

        $userUuid = $this->getCurrentUserUuid();

        // Check ownership based on table-specific ownership field
        switch ($table) {
            case 'profiles':
                if (isset($record['user_uuid']) && $record['user_uuid'] !== $userUuid) {
                    $this->requirePermission('admin.profiles.manage');
                }
                break;
            case 'blobs':
                if (isset($record['created_by']) && $record['created_by'] !== $userUuid) {
                    $this->requirePermission('admin.files.manage');
                }
                break;
        }
    }

    // =================================================================
    // Helper Methods
    // =================================================================

    /**
     * Parse query conditions from request parameters
     */
    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    protected function parseConditions(array $queryParams): array
    {
        $conditions = [];

        // Add any filter conditions from query params
        foreach ($queryParams as $key => $value) {
            // Skip pagination and sorting parameters
            if (in_array($key, ['page', 'per_page', 'sort', 'order', 'fields'], true)) {
                continue;
            }

            // Simple equality conditions for now
            $conditions[$key] = $value;
        }

        return $conditions;
    }

    /**
     * Parse fields to select from request parameters
     */
    /**
     * @return array<int, string>
     */
    protected function parseFields(string $fields): array
    {
        if ($fields === '' || $fields === '*') {
            return [];
        }

        return array_map('trim', explode(',', $fields));
    }

    // =========================================================================
    // Bulk Operations (delegates to BulkOperationsTrait)
    // =========================================================================

    /**
     * Bulk delete resources
     *
     * @route DELETE /data/{table}/bulk
     */
    public function destroyBulk(Request $request): Response
    {
        $params = ['resource' => $request->attributes->get('table', '')];
        $deleteData = RequestHelper::getRequestData();
        return $this->bulkDelete($params, $deleteData);
    }

    /**
     * Bulk update resources
     *
     * @route PUT /data/{table}/bulk
     */
    public function updateBulk(Request $request): Response
    {
        $params = ['resource' => $request->attributes->get('table', '')];
        $updateData = RequestHelper::getRequestData();
        return $this->bulkUpdate($params, $updateData);
    }
}
