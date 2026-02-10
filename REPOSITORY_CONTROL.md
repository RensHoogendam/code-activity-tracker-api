# Repository Enable/Disable Control

This feature allows users to control which repositories are enabled or disabled for their account.

## Database Structure

### `user_repositories` table
- `user_id` - Links to the user
- `repository_id` - Links to the repository
- `is_primary` - Marks if this is a primary repository for the user
- `is_enabled` - **NEW** - Controls if the repository is enabled for the user (default: true)

## API Endpoints

### Get User Repositories (Updated)
```
GET /api/repositories/user
```
Now includes `is_enabled` status in the response:
```json
{
  "data": [
    {
      "id": 1,
      "name": "my-repo",
      "full_name": "workspace/my-repo",
      "workspace": "workspace",
      "description": "My repository",
      "language": "PHP",
      "is_primary": false,
      "is_enabled": true
    }
  ],
  "user_id": 1
}
```

### Enable Repository
```
PATCH /api/repositories/user/{repository_id}/enable
```
Enables a repository for the user.

### Disable Repository
```
PATCH /api/repositories/user/{repository_id}/disable
```
Disables a repository for the user.

### Toggle Repository Status
```
PATCH /api/repositories/user/{repository_id}/toggle
```
Toggles the enabled/disabled status of a repository.

Response format for enable/disable/toggle:
```json
{
  "message": "Repository enabled successfully",
  "is_enabled": true
}
```

## Model Methods

### User Model
- `repositories()` - All user repositories (includes `is_enabled` pivot)
- `enabledRepositories()` - Only enabled repositories
- `enabledPrimaryRepositories()` - Only enabled primary repositories
- `primaryRepositories()` - Primary repositories (existing method)

### Repository Model
- `users()` - All users linked to repository (includes `is_enabled` pivot)
- `enabledUsers()` - Only users who have this repository enabled

## Usage Examples

### Get only enabled repositories for a user:
```php
$user = User::find(1);
$enabledRepos = $user->enabledRepositories;
```

### Get enabled primary repositories:
```php
$user = User::find(1);
$enabledPrimaryRepos = $user->enabledPrimaryRepositories;
```

### Check if a specific repository is enabled for a user:
```php
$user = User::find(1);
$repository = $user->repositories()->where('repository_id', 1)->first();
$isEnabled = $repository->pivot->is_enabled;
```

### Enable/Disable a repository programmatically:
```php
$user = User::find(1);
$repositoryId = 1;

// Enable
$user->repositories()->updateExistingPivot($repositoryId, ['is_enabled' => true]);

// Disable
$user->repositories()->updateExistingPivot($repositoryId, ['is_enabled' => false]);
```

## Migration Files
- `2026_02_02_151441_add_is_enabled_to_user_repositories_table.php` - Adds the `is_enabled` column