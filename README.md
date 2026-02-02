# Hours Tracking API

A Laravel-based REST API backend for the Hours tracking application with Bitbucket integration.

## Features

- 🔗 **Bitbucket Integration**: Fetch commits and pull requests from Bitbucket repositories
- ⚡ **Server-side Filtering**: Efficient API calls with server-side author filtering
- 💾 **Intelligent Caching**: Automatic caching with configurable TTL
- 🌐 **CORS Support**: Ready for frontend integration
- 🔐 **Secure**: API tokens stored server-side
- 📊 **Deduplication**: Eliminates duplicate commits from multiple sources

## API Endpoints

### Bitbucket Integration
- `GET /api/bitbucket/commits` - Get commits and pull requests for time tracking
- `GET /api/bitbucket/repositories` - Get available repositories
- `GET /api/bitbucket/test-auth` - Test Bitbucket authentication
- `DELETE /api/bitbucket/cache` - Clear API cache

## Installation

1. **Install dependencies**:
   ```bash
   composer install
   ```

2. **Environment Configuration**:
   Copy `.env.example` to `.env` and configure:
   ```bash
   cp .env.example .env
   ```

3. **Configure Bitbucket Integration**:
   Update your `.env` file with Bitbucket credentials:
   ```env
   BITBUCKET_USERNAME=your-email@example.com
   BITBUCKET_TOKEN=your-api-token
   BITBUCKET_WORKSPACES=workspace1,workspace2
   BITBUCKET_AUTHOR_DISPLAY_NAME="Your Name"
   BITBUCKET_AUTHOR_EMAIL=your-email@example.com
   ```

4. **Generate Application Key**:
   ```bash
   php artisan key:generate
   ```

5. **Run Database Migrations**:
   ```bash
   php artisan migrate
   ```

## Running the Server

Start the development server:
```bash
php artisan serve
```

The API will be available at `http://localhost:8000`

## API Usage

### Get Commits and Pull Requests
```bash
GET /api/bitbucket/commits?days=14&force_refresh=false
```

**Parameters:**
- `days` (optional): Number of days to fetch data for (1-365, default: 14)
- `repositories` (optional): Array of specific repositories to include
- `force_refresh` (optional): Force cache refresh (default: false)

**Response:**
```json
{
  "data": [
    {
      "type": "commit",
      "repository": "workspace/repo-name",
      "hash": "abc123",
      "date": "2024-02-02T12:00:00Z",
      "message": "Fix bug in authentication",
      "author_raw": "John Doe <john@example.com>",
      "ticket": "PROJ-123"
    },
    {
      "type": "pull_request",
      "repository": "workspace/repo-name",
      "id": 42,
      "title": "Add new feature",
      "author": "John Doe",
      "created_on": "2024-02-01T10:00:00Z",
      "updated_on": "2024-02-02T11:00:00Z",
      "state": "MERGED",
      "ticket": "PROJ-124"
    }
  ],
  "cached": false,
  "cache_expires_at": "2024-02-02T12:30:00Z"
}
```

### Test Authentication
```bash
GET /api/bitbucket/test-auth
```

**Response:**
```json
{
  "success": true,
  "message": "Authentication successful",
  "user": {
    "username": "john.doe",
    "display_name": "John Doe",
    "account_id": "123456"
  }
}
```

## Configuration

### Bitbucket Setup

1. **Create App Password**:
   - Go to Bitbucket Settings → App passwords
   - Create new app password with "Repositories: Read" and "Pull requests: Read" permissions

2. **Configure Environment Variables**:
   - `BITBUCKET_USERNAME`: Your Atlassian account email
   - `BITBUCKET_TOKEN`: Your app password
   - `BITBUCKET_WORKSPACES`: Comma-separated list of workspace slugs
   - `BITBUCKET_AUTHOR_DISPLAY_NAME`: Your display name for filtering PRs
   - `BITBUCKET_AUTHOR_EMAIL`: Your email for filtering commits

### Caching

The API automatically caches responses for:
- Commits/PRs: 30 minutes
- Repositories: 1 hour

Clear cache with:
```bash
DELETE /api/bitbucket/cache
```

## Architecture

- **Controller**: `App\Http\Controllers\Api\BitbucketController` - Handles API requests
- **Service**: `App\Services\BitbucketService` - Bitbucket API integration
- **Configuration**: `config/services.php` - Service configuration
- **Routes**: `routes/api.php` - API route definitions

## Development

### Running Tests
```bash
php artisan test
```

### Code Quality
```bash
./vendor/bin/pint  # Laravel Pint for code formatting
```

### Task Management
Use VS Code tasks or run directly:
- **Laravel Development Server**: `php artisan serve`
- **Queue Worker**: `php artisan queue:work`
- **Cache Clear**: `php artisan cache:clear`

## Integration with Frontend

The API is designed to work with the Vue.js frontend application. Configure your frontend to make requests to:

```javascript
const API_BASE_URL = 'http://localhost:8000/api';

// Example usage
const response = await fetch(`${API_BASE_URL}/bitbucket/commits?days=30`);
const data = await response.json();
```

## Troubleshooting

### Authentication Issues
- Verify your Bitbucket credentials in `.env`
- Ensure you're using your email address (not username) for `BITBUCKET_USERNAME`
- Check that your app password has the correct permissions

### API Rate Limits
- The service implements intelligent caching to minimize API calls
- Use server-side filtering to reduce response sizes
- Monitor Bitbucket API rate limits in your account settings

### CORS Issues
- CORS is pre-configured for `api/*` routes
- Update `config/cors.php` if you need custom CORS settings

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
