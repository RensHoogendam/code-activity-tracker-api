# Hours Tracking API - Laravel Backend

This workspace contains a Laravel-based REST API backend for the Hours tracking application with Bitbucket integration.

## Project Structure
- **Backend API**: Laravel 12 with Bitbucket integration
- **Features**: Server-side filtering, caching, CORS support, deduplication
- **Endpoints**: Commits, pull requests, repositories, authentication testing

## Development Setup
1. Configure Bitbucket credentials in `.env`
2. Run `php artisan serve` to start the development server
3. API available at `http://localhost:8000/api`

## Key Files
- `app/Services/BitbucketService.php` - Core Bitbucket integration
- `app/Http/Controllers/Api/BitbucketController.php` - API endpoints
- `routes/api.php` - API routes definition
- `config/services.php` - Service configuration

## API Endpoints
- `GET /api/bitbucket/commits` - Get commits and pull requests
- `GET /api/bitbucket/repositories` - Get available repositories  
- `GET /api/bitbucket/test-auth` - Test authentication
- `DELETE /api/bitbucket/cache` - Clear cache
