# 🚀 Laravel Pipedrive Integration

A comprehensive Laravel package for seamless Pipedrive CRM integration. Sync entities, manage custom fields, and leverage Eloquent relationships with a robust JSON-based data structure for maximum flexibility and performance.

## ✨ **Features**

- 🔄 **Complete Entity Synchronization** - Activities, Deals, Files, Notes, Organizations, Persons, Pipelines, Products, Stages, Users, Goals
- 🔔 **Real-Time Webhooks** - Instant synchronization with secure webhook handling
- � **Scheduled Synchronization** - Automated background sync with configurable frequency
- 🛡️ **API Rate Limiting** - Built-in delays to prevent API rate limit issues
- �🔗 **Eloquent Relationships** - Navigate between entities with Laravel's relationship system
- 🎯 **Custom Fields Management** - Full support for Pipedrive custom fields with automated synchronization
- 🏗️ **Hybrid Data Structure** - Essential columns + JSON storage for maximum flexibility
- 🔐 **Dual Authentication** - Support for both API tokens and OAuth
- ⚡ **Performance Optimized** - Efficient queries with proper indexing
- 📊 **Rich Querying** - Advanced filtering and relationship queries
- 🔄 **Automatic Entity Merging** - Seamless handling of entity merges with relationship continuity
- 🤖 **Smart Custom Field Detection** - Real-time detection and sync of new custom fields via webhooks

## 🛡️ **Production-Ready Robustness**

This package includes enterprise-grade robustness features designed for production environments:

### **Smart Rate Limiting**
- 🚦 **Token-based system** supporting Pipedrive's December 2024 rate limiting changes
- 📊 **Daily budget tracking** with automatic token consumption monitoring
- ⏱️ **Exponential backoff** with intelligent retry strategies
- 🎯 **Per-endpoint cost calculation** for optimal API usage

### **Intelligent Error Handling**
- 🔄 **Circuit breaker pattern** prevents cascading failures
- 🎯 **Error classification** with specific retry strategies for different error types
- 🔍 **Automatic exception classification** (rate limits, auth, server errors, etc.)
- 📈 **Failure tracking** with automatic recovery detection

### **Adaptive Memory Management**
- 🧠 **Real-time memory monitoring** with automatic alerts
- 📏 **Dynamic batch size adjustment** based on memory usage
- 🗑️ **Automatic garbage collection** during large operations
- ⚠️ **Memory threshold warnings** with suggested optimizations

### **API Health Monitoring**
- 💚 **Continuous health checks** with cached status
- 📊 **Performance degradation detection** with response time monitoring
- 🔄 **Automatic recovery** when API health improves
- 📈 **Health statistics** and trend analysis

### **Centralized Job Architecture**
- 🏗️ **Unified processing** eliminates code duplication
- ⚡ **Dual execution modes** (synchronous for commands, asynchronous for schedulers)
- 📊 **Progress tracking** with detailed statistics
- 🔄 **Automatic retry logic** with intelligent backoff strategies

## 📦 **Installation**

Install the package via Composer:

```bash
composer require skeylup/laravel-pipedrive
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="laravel-pipedrive-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-pipedrive-config"
```

## ⚙️ **Configuration**

Add your Pipedrive credentials to your `.env` file:

### **API Token Authentication (Recommended for Simple Integrations)**

The simplest way to authenticate with Pipedrive. Get your API token from Pipedrive:

1. **Log into your Pipedrive account**
2. **Go to Settings → Personal preferences → API**
3. **Copy your API token**
4. **Add to your `.env` file:**

```env
PIPEDRIVE_AUTH_METHOD=token
PIPEDRIVE_TOKEN=your_api_token_here
```

### **OAuth 2.0 Authentication (Recommended for Production Apps)**

OAuth provides more secure authentication and is required for public applications. Follow these steps:

#### **Step 1: Create a Pipedrive App**

1. **Go to [Pipedrive Developer Hub](https://developers.pipedrive.com/)**
2. **Sign in with your Pipedrive account**
3. **Click "Create an app"**
4. **Fill in your app details:**
   - **App name**: Your application name
   - **App description**: Brief description of your app
   - **App URL**: Your application's homepage URL
   - **Callback URL**: `https://your-domain.com/pipedrive/oauth/callback`
5. **Select required scopes** (permissions your app needs)
6. **Submit for review** (for public apps) or **create as private app**

#### **Step 2: Get Your OAuth Credentials**

After app creation/approval, you'll receive:
- **Client ID**: Public identifier for your app
- **Client Secret**: Secret key (keep this secure!)

#### **Step 3: Configure Your Laravel App**

Add OAuth credentials to your `.env` file:

```env
PIPEDRIVE_AUTH_METHOD=oauth
PIPEDRIVE_CLIENT_ID=your_client_id_from_pipedrive
PIPEDRIVE_CLIENT_SECRET=your_client_secret_from_pipedrive
PIPEDRIVE_REDIRECT_URL=https://your-domain.com/pipedrive/oauth/callback
```

#### **Step 4: OAuth Web Interface (Built-in)**

The package includes a complete OAuth web interface with beautiful UI pages. After configuration, you can use these routes:

```bash
# OAuth management routes (automatically registered)
/pipedrive/oauth/authorize    # Start OAuth flow (protected)
/pipedrive/oauth/callback     # OAuth callback (set this in Pipedrive app)
/pipedrive/oauth/status       # Check connection status (protected)
/pipedrive/oauth/disconnect   # Disconnect from Pipedrive (protected)
/pipedrive/webhook/health     # Webhook health check (protected)
```

> **🔒 Security Note**: Most routes are protected by dashboard authorization in non-local environments. See [Dashboard Authorization](docs/authorization/dashboard-authorization.md) for configuration details.

#### **Step 5: Initiate OAuth Flow**

**Option A: Use Built-in Web Interface**
1. Visit `/pipedrive/oauth/status` to check current status
2. Click "Connect to Pipedrive" or visit `/pipedrive/oauth/authorize`
3. You'll see a beautiful authorization page with scope details
4. Click "Connect to Pipedrive" to redirect to Pipedrive
5. Authorize your app on Pipedrive
6. You'll be redirected back with a success page

**Option B: Programmatic OAuth (Custom Implementation)**
```php
// In your controller
public function connectToPipedrive()
{
    $authService = app(\Skeylup\LaravelPipedrive\Services\PipedriveAuthService::class);
    $pipedrive = $authService->getPipedriveInstance();

    $authUrl = $pipedrive->getAuthorizationUrl([
        'scope' => 'deals:read deals:write persons:read persons:write'
    ]);

    return redirect($authUrl);
}
```

#### **Step 6: Non-Expiring Tokens**

The package automatically handles token storage and refresh. For non-expiring tokens:
- Tokens are stored securely in cache with long TTL (1 year for non-expiring tokens)
- No manual refresh needed for non-expiring tokens
- Automatic refresh for expiring tokens (when supported by Pipedrive)
- Use `/pipedrive/oauth/status` to monitor token status

#### **OAuth vs API Token Comparison**

| Feature | API Token | OAuth 2.0 |
|---------|-----------|-----------|
| **Setup Complexity** | Simple | Moderate |
| **Security** | Good | Excellent |
| **Token Expiration** | Never | Yes (with refresh) |
| **User Consent** | Not required | Required |
| **Multi-user Support** | No | Yes |
| **Recommended For** | Personal/Internal apps | Production/Public apps |
| **Pipedrive App Store** | Not eligible | Required |

### **Scheduled Synchronization & Robustness Configuration**
```env
# Enable scheduled sync (SAFE MODE - always uses standard sync, never full-data)
PIPEDRIVE_SCHEDULER_ENABLED=true
PIPEDRIVE_SCHEDULER_FREQUENCY=24
PIPEDRIVE_SCHEDULER_TIME=02:00
PIPEDRIVE_SCHEDULER_LIMIT=500

# Custom fields automatic synchronization
PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_ENABLED=true
PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_FREQUENCY=1
PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_FORCE=true

# Webhook custom field detection
PIPEDRIVE_WEBHOOKS_DETECT_CUSTOM_FIELDS=true

# Entity configuration
PIPEDRIVE_ENABLED_ENTITIES=deals,activities,persons,organizations,products

# Robustness features
PIPEDRIVE_RATE_LIMITING_ENABLED=true
PIPEDRIVE_DAILY_TOKEN_BUDGET=10000
PIPEDRIVE_MEMORY_THRESHOLD=80
PIPEDRIVE_HEALTH_MONITORING_ENABLED=true
PIPEDRIVE_CIRCUIT_BREAKER_THRESHOLD=5
```

## 🚀 **Quick Start**

### **Test Your Connection**
```bash
php artisan pipedrive:test-connection
```

### **View Configuration**
```bash
# Show current entity configuration
php artisan pipedrive:config --entities

# Show full configuration
php artisan pipedrive:config

# JSON output
php artisan pipedrive:config --json
```

### **Sync Entities from Pipedrive**
```bash
# Sync all enabled entities
php artisan pipedrive:sync-entities

# Sync specific entity (even if disabled)
php artisan pipedrive:sync-entities --entity=deals --limit=50

# Verbose output with configuration details
php artisan pipedrive:sync-entities --entity=users --verbose
```

### **Sync Custom Fields**
```bash
# Sync all custom fields
php artisan pipedrive:sync-custom-fields

# Sync specific entity fields
php artisan pipedrive:sync-custom-fields --entity=deal --verbose

# Force sync (skip confirmations)
php artisan pipedrive:sync-custom-fields --force

# Full data mode with pagination (use with caution)
php artisan pipedrive:sync-custom-fields --full-data --force
```

**🤖 Automatic Custom Field Synchronization:**
- **Hourly Scheduler**: Automatically syncs custom fields every hour (configurable)
- **Webhook Detection**: Real-time detection of new custom fields in entity updates
- **Smart Triggering**: Only syncs when new fields are detected to minimize API usage

### **Scheduled Synchronization**
```bash
# Run scheduled sync manually (SAFE MODE - always uses standard sync)
php artisan pipedrive:scheduled-sync

# Test configuration (dry run)
php artisan pipedrive:scheduled-sync --dry-run

# Verbose output for debugging
php artisan pipedrive:scheduled-sync --verbose
```

**🛡️ Safety Features**:
- Scheduled sync **ALWAYS** uses standard mode (limit=500, sorted by last modified)
- **NEVER** uses full-data mode for safety and performance
- Includes comprehensive robustness features (rate limiting, error handling, memory management)
- Automatic retry logic with circuit breaker protection

### **Real-Time Webhooks**
```bash
# Setup webhook for real-time sync
php artisan pipedrive:webhooks create \
    --url=https://your-app.com/pipedrive/webhook \
    --event=*.* \
    --auth-user=webhook_user \
    --auth-pass=secure_password

# List existing webhooks
php artisan pipedrive:webhooks list

# Test webhook endpoint
curl https://your-app.com/pipedrive/webhook/health
```

## 🔒 **Dashboard Authorization**

The package includes a Telescope-like authorization system to protect management routes in production:

### **Quick Setup**
```bash
# Complete installation (config, migrations, views, service provider)
php artisan pipedrive:install

# Run migrations
php artisan migrate

# Add to config/app.php providers array
App\Providers\PipedriveServiceProvider::class,
```

### **Configuration Options**

**Option 1: Simple Email/ID Authorization**
```php
// config/pipedrive.php
'dashboard' => [
    'authorized_emails' => [
        'admin@example.com',
        'developer@example.com',
    ],
    'authorized_user_ids' => [1, 2],
],
```

**Option 2: Custom Gate Logic**
```php
// app/Providers/PipedriveServiceProvider.php
Gate::define('viewPipedrive', function ($user) {
    return $user->hasRole('admin') || $user->can('manage-pipedrive');
});
```

**Protected Routes**: `/pipedrive/oauth/*`, `/pipedrive/webhook/health`

📖 **[Complete Authorization Guide →](docs/authorization/dashboard-authorization.md)**

## 📊 **Models & Relationships**

All Pipedrive entities are available as Eloquent models with full relationship support:

```php
use Skeylup\LaravelPipedrive\Models\{
    PipedriveActivity, PipedriveDeal, PipedriveFile, PipedriveNote,
    PipedriveOrganization, PipedrivePerson, PipedrivePipeline,
    PipedriveProduct, PipedriveStage, PipedriveUser, PipedriveGoal
};

// Link your Laravel models to Pipedrive entities
use Skeylup\LaravelPipedrive\Traits\HasPipedriveEntity;
use Skeylup\LaravelPipedrive\Enums\PipedriveEntityType;

class Order extends Model
{
    use HasPipedriveEntity;

    // Define default Pipedrive entity type
    protected PipedriveEntityType $pipedriveEntityType = PipedriveEntityType::DEALS;

    public function linkToDeal(int $dealId): void
    {
        $this->linkToPipedriveEntity($dealId, true);
    }
}

// Navigate relationships
$deal = PipedriveDeal::with(['user', 'person', 'organization', 'stage'])->first();
echo $deal->user->name;         // Deal owner
echo $deal->person->name;       // Contact person
echo $deal->organization->name; // Company
echo $deal->stage->name;        // Current stage

// Reverse relationships
$user = PipedriveUser::with(['deals', 'activities'])->first();
echo $user->deals->count();     // Number of deals
echo $user->activities->count(); // Number of activities
```

## 🔗 **Entity Linking**

Link your Laravel models to Pipedrive entities with morphic relationships:

```php
// In your Laravel model
class Order extends Model
{
    use HasPipedriveEntity;

    // Set default entity type
    protected PipedriveEntityType $pipedriveEntityType = PipedriveEntityType::DEALS;
}

// Usage
$order = Order::create([...]);

// Link to Pipedrive deal (uses default entity type)
$order->linkToPipedriveEntity(123, true, ['source' => 'manual']);

// Link to additional entities
$order->linkToPipedrivePerson(456, false, ['role' => 'customer']);
$order->linkToPipedriveOrganization(789, false, ['type' => 'client']);

// Get linked entities
$deal = $order->getPrimaryPipedriveEntity();
$persons = $order->getPipedrivePersons();

// Check if linked
if ($order->isLinkedToPipedriveEntity(123)) {
    // Order is linked to deal 123
}

// Push modifications to Pipedrive (async by default)
$result = $order->pushToPipedrive([
    'title' => 'Updated Order',
    'value' => 1500.00,
], [
    'Order Number' => $order->order_number,
    'Customer Email' => $order->customer_email,
]);

// Force synchronous execution
$result = $order->pushToPipedrive($modifications, $customFields, true);

// Use custom queue with retries
$result = $order->pushToPipedrive($modifications, $customFields, false, 'high-priority', 5);

// Display details with readable custom field names
$details = $order->displayPipedriveDetails();
foreach ($details['custom_fields'] as $name => $fieldData) {
    echo "{$name}: {$fieldData['value']}\n";
}

// Manage links via Artisan
php artisan pipedrive:entity-links stats
php artisan pipedrive:entity-links sync
php artisan pipedrive:entity-links cleanup
```

## 📡 **Events**

Listen to Pipedrive entity changes with Laravel events:

```php
// In EventServiceProvider.php
protected $listen = [
    PipedriveEntityCreated::class => [
        App\Listeners\NewDealNotificationListener::class,
    ],
    PipedriveEntityUpdated::class => [
        App\Listeners\DealStatusChangeListener::class,
    ],
    PipedriveEntityDeleted::class => [
        App\Listeners\CleanupListener::class,
    ],
];

// Example listener
public function handle(PipedriveEntityUpdated $event)
{
    if ($event->isDeal() && $event->hasChanged('status')) {
        $deal = $event->entity;
        $newStatus = $event->getNewValue('status');

        if ($newStatus === 'won') {
            CreateInvoiceJob::dispatch($deal);
        }
    }
}
```

## 🔍 **Querying Data**

### **Basic Queries**
```php
// Active deals with high value
$deals = PipedriveDeal::where('status', 'open')
    ->where('value', '>', 10000)
    ->active()
    ->get();

// Overdue activities
$activities = PipedriveActivity::where('done', false)
    ->where('due_date', '<', now())
    ->with('user')
    ->get();
```

### **Relationship Queries**
```php
// Deals from specific organization
$deals = PipedriveDeal::whereHas('organization', function($query) {
    $query->where('name', 'like', '%Acme Corp%');
})->get();

// Activities assigned to specific user
$activities = PipedriveActivity::whereHas('user', function($query) {
    $query->where('email', 'john@example.com');
})->get();
```

### **JSON Data Access**
```php
$activity = PipedriveActivity::first();

// Essential data (columns)
echo $activity->subject;
echo $activity->type;
echo $activity->done;

// Extended data (JSON)
echo $activity->getPipedriveAttribute('note');
echo $activity->getPipedriveAttribute('duration');
echo $activity->getPipedriveAttribute('location');

// Direct JSON access
$allData = $activity->pipedrive_data;
$customField = $activity->pipedrive_data['custom_field_hash'] ?? null;
```

## 🔄 **Advanced Synchronization**

### **Smart Sync Commands**
```bash
# Standard mode: Latest modifications (optimized)
php artisan pipedrive:sync-entities --entity=deals --limit=500

# Full data mode: ALL data with pagination (use with caution)
php artisan pipedrive:sync-entities --entity=deals --full-data

# Custom fields sync
php artisan pipedrive:sync-custom-fields --entity=deal
```

**Key Features:**
- **Smart Sorting**: Latest modifications first (default) or chronological for full sync
- **API Optimization**: Respects Pipedrive API limits (max 500 per request)
- **Pagination Support**: Automatic pagination for large datasets
- **Safety Warnings**: Built-in warnings for resource-intensive operations

⚠️ **Important**: Use `--full-data` with caution due to API rate limits. See [Sync Commands Documentation](docs/commands/sync-commands.md) for details.

## ⚙️ **Entity Configuration**

Control which Pipedrive entities are synchronized using the `PIPEDRIVE_ENABLED_ENTITIES` environment variable:

```bash
# Enable specific entities (comma-separated)
PIPEDRIVE_ENABLED_ENTITIES=deals,activities,persons,organizations

# Enable all entities
PIPEDRIVE_ENABLED_ENTITIES=all

# Disable all entities (empty value)
PIPEDRIVE_ENABLED_ENTITIES=
```

### **Available Entities**
- `activities` - Activities/Tasks
- `deals` - Deals/Opportunities
- `files` - Files/Attachments
- `goals` - Goals/Targets
- `notes` - Notes/Comments
- `organizations` - Companies/Organizations
- `persons` - People/Contacts
- `pipelines` - Sales Pipelines
- `products` - Products/Services
- `stages` - Pipeline Stages
- `users` - Users/Team Members

### **Configuration Commands**
```bash
# View current configuration
php artisan pipedrive:config

# View only entity configuration
php artisan pipedrive:config --entities

# Export configuration as JSON
php artisan pipedrive:config --json
```

### **Behavior**
- **Commands**: Only enabled entities are synchronized by default
- **Schedulers**: Only enabled entities are included in automated sync
- **Force Override**: Use `--force` flag to sync disabled entities manually
- **Validation**: Invalid entity names in configuration are ignored with warnings

## 🔗 **Webhook Management**

The package provides intelligent webhook management with smart configuration defaults:

### **Smart Webhook Creation**
```bash
# Quick setup with auto-configuration (recommended)
php artisan pipedrive:webhooks create --auto-config

# Interactive setup with intelligent suggestions
php artisan pipedrive:webhooks create --verbose

# Test webhook connectivity
php artisan pipedrive:webhooks test
```

### **Configuration-Aware Features**
- **Auto URL Detection**: Uses `APP_URL` + webhook path from config
- **Smart Event Suggestions**: Based on your `PIPEDRIVE_ENABLED_ENTITIES`
- **Auto Authentication**: Uses configured HTTP Basic Auth credentials
- **Connectivity Testing**: Validates webhook endpoints before creation

### **Example Workflow**
```bash
# 1. Configure your environment
APP_URL=https://your-app.com
PIPEDRIVE_ENABLED_ENTITIES=deals,activities,persons
PIPEDRIVE_WEBHOOK_BASIC_AUTH_ENABLED=true
PIPEDRIVE_WEBHOOK_BASIC_AUTH_USERNAME=admin
PIPEDRIVE_WEBHOOK_BASIC_AUTH_PASSWORD=secure_password

# 2. Test webhook connectivity
php artisan pipedrive:webhooks test

# 3. Create webhook with smart defaults
php artisan pipedrive:webhooks create --auto-config --test-url

# 4. List existing webhooks
php artisan pipedrive:webhooks list
```

### **Manual Configuration**
```bash
# Create webhook manually
php artisan pipedrive:webhooks create \
  --url="https://your-app.com/pipedrive/webhook" \
  --event="*.*" \
  --auth-user="webhook_user" \
  --auth-pass="secure_password"

# Delete webhook
php artisan pipedrive:webhooks delete --id=123
```

See [Webhook Management Documentation](docs/webhooks/webhook-management.md) for detailed usage.

## 🎯 **Custom Fields**

### **Manual Management**
```php
use Skeylup\LaravelPipedrive\Models\PipedriveCustomField;

// Get all deal fields
$dealFields = PipedriveCustomField::forEntity('deal')->active()->get();

// Get only custom fields (not default Pipedrive fields)
$customFields = PipedriveCustomField::forEntity('deal')->customOnly()->get();

// Get mandatory fields
$mandatoryFields = PipedriveCustomField::forEntity('deal')->mandatory()->get();

// Access field properties
foreach ($dealFields as $field) {
    echo "Field: {$field->name} ({$field->field_type})\n";
    echo "Mandatory: " . ($field->isMandatory() ? 'Yes' : 'No') . "\n";

    if ($field->hasOptions()) {
        echo "Options: " . implode(', ', $field->getOptions()) . "\n";
    }
}
```

### **🤖 Automatic Synchronization**

**Hourly Scheduler:**
```env
# Enable automatic hourly sync (default: true)
PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_ENABLED=true

# Sync frequency in hours (default: 1 hour)
PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_FREQUENCY=1

# Force sync without confirmations (default: true)
PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_FORCE=true
```

**Real-Time Webhook Detection:**
```env
# Enable detection of new custom fields in webhooks (default: true)
PIPEDRIVE_WEBHOOKS_DETECT_CUSTOM_FIELDS=true
```

**How It Works:**
- **Webhook Processing**: Automatically detects new custom fields (40-character hash keys) in entity updates
- **Smart Detection**: Compares with known fields and triggers sync only when new fields are found
- **Supported Entities**: Deals, Persons, Organizations, Products, Activities
- **Asynchronous Processing**: Uses queue jobs to avoid blocking webhook processing
- **Error Handling**: Graceful error handling that doesn't interrupt main webhook processing

See [Custom Field Automation Documentation](docs/features/custom-field-automation.md) for detailed configuration and monitoring.

## 📚 **Documentation**

### **Core Features**
- [📖 **Models & Relationships**](docs/models-relationships.md) - Complete guide to all models and their relationships
- [🔄 **Data Synchronization**](docs/synchronization.md) - Entity and custom field sync strategies
- [⚡ **Sync Commands**](docs/commands/sync-commands.md) - Advanced sync commands with pagination and sorting
- [🔔 **Real-Time Webhooks**](docs/webhooks.md) - Instant synchronization with webhook handling
- [🎯 **Custom Fields**](docs/custom-fields.md) - Working with Pipedrive custom fields
- [🔐 **Authentication**](docs/authentication.md) - API token and OAuth setup

### **Advanced Features**
- [🔗 **Entity Linking**](docs/entity-linking.md) - Link Laravel models to Pipedrive entities
- [🚀 **Push to Pipedrive**](docs/push-to-pipedrive.md) - Sync modifications back to Pipedrive
- [📡 **Events System**](docs/events.md) - Laravel events for Pipedrive operations
- [🔄 **Entity Merging**](docs/entity-merging.md) - Automatic handling of entity merges
- [🔗 **Using Relations**](docs/relations-usage.md) - Navigate between Pipedrive entities
- [🤖 **Custom Field Automation**](docs/features/custom-field-automation.md) - Automated custom field synchronization

### **Technical Reference**
- [🏗️ **Database Structure**](docs/database-structure.md) - Understanding the hybrid data approach
- [⚡ **Performance**](docs/performance.md) - Optimization tips and best practices

## 🛠️ **Commands Reference**

| Command | Description | Options |
|---------|-------------|---------|
| `pipedrive:test-connection` | Test Pipedrive API connection | - |
| `pipedrive:sync-entities` | Sync Pipedrive entities | `--entity`, `--limit`, `--force`, `--verbose` |
| `pipedrive:sync-custom-fields` | Sync custom fields (with automatic scheduling) | `--entity`, `--force`, `--full-data`, `--verbose` |
| `pipedrive:scheduled-sync` | Automated scheduled sync | `--dry-run`, `--verbose` |
| `pipedrive:webhooks` | Manage webhooks (list/create/delete/test) with smart configuration | `action`, `--url`, `--event`, `--id`, `--auth-user`, `--auth-pass`, `--auto-config`, `--test-url`, `--verbose` |
| `pipedrive:entity-links` | Manage entity links (stats/sync/cleanup/list) | `action`, `--entity-type`, `--model-type`, `--status`, `--limit`, `--verbose` |

## 🏗️ **Database Structure**

Each Pipedrive entity table follows this hybrid structure:

```sql
-- Essential columns (indexed, queryable)
id                    -- Laravel auto-increment
pipedrive_id          -- Unique Pipedrive ID
name/title/subject    -- Main identifier
[relationships]       -- Foreign keys (user_id, person_id, etc.)
active_flag           -- Status flag

-- JSON storage (flexible, all other data)
pipedrive_data        -- Complete Pipedrive data as JSON

-- Timestamps
pipedrive_add_time    -- Pipedrive creation time
pipedrive_update_time -- Pipedrive modification time
created_at/updated_at -- Laravel timestamps
```

## 📚 **Documentation**

Comprehensive documentation is available in the `docs/` directory:

### Core Features
- [Authentication](docs/authentication.md) - API token and OAuth setup
- [Synchronization](docs/synchronization.md) - Data sync strategies and best practices
- [Custom Fields](docs/custom-fields.md) - Managing Pipedrive custom fields
- [Custom Field Automation](docs/features/custom-field-automation.md) - Automated synchronization and detection
- [Entity Merging](docs/entity-merging.md) - Automatic handling of entity merges
- [Webhooks](docs/webhooks.md) - Real-time data synchronization
- [Entity Linking](docs/entity-linking.md) - Connect Laravel models to Pipedrive entities

### Commands
- [Sync Commands](docs/commands/sync-commands.md) - Complete sync command reference
- [Scheduled Sync](docs/commands/scheduled-sync.md) - Automated synchronization setup

### Robustness & Production Features
- [Robustness Overview](docs/robustness/overview.md) - Production-ready features and architecture
- [Troubleshooting Guide](docs/robustness/troubleshooting.md) - Common issues and solutions

### Advanced Topics
- [Models & Relationships](docs/models-relationships.md) - Eloquent relationships and querying
- [Performance](docs/performance.md) - Optimization and best practices
- [Database Structure](docs/database-structure.md) - Table schemas and indexing
- [Events](docs/events.md) - Event system and listeners

## 🤝 **Contributing**

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 🔒 **Security**

If you discover any security-related issues, please email kevin.eggermont@gmail.com instead of using the issue tracker.

## 📄 **License**

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## 🙏 **Credits**

- Built on top of [devio/pipedrive](https://github.com/IsraelOrtuno/pipedrive)
- Plugin template from [spatie/laravel-package-tools](https://github.com/spatie/laravel-package-tools)
- Powered by [Laravel](https://laravel.com)
