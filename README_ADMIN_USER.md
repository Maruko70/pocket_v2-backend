# Creating Admin Users

## Option 1: Using Seeder (Recommended for First Admin)

Run the seeder to create a default admin user:

```bash
cd backend
php artisan db:seed --class=AdminUserSeeder
```

**Default Credentials:**
- Phone: `0912345678`
- Password: `password`

⚠️ **Important:** Change the default password after first login!

## Option 2: Using Tinker (For Additional Admins)

You can create additional admin users using Laravel Tinker:

```bash
cd backend
php artisan tinker
```

Then run:

```php
use App\Models\AdminUser;
use App\Rules\SudanPhoneNumber;
use Illuminate\Support\Facades\Hash;

AdminUser::create([
    'name' => 'Admin Name',
    'phone' => SudanPhoneNumber::normalize('0912345678'), // Your phone number
    'password' => Hash::make('your-secure-password'),
    'role' => 'admin',
    'permissions' => null,
]);

// Example: grant specific permissions (including notifications)
AdminUser::create([
    'name' => 'Manager With Notifications',
    'phone' => SudanPhoneNumber::normalize('0912345679'),
    'password' => Hash::make('your-secure-password'),
    'role' => 'manager',
    'permissions' => [
        'reservations.manage',
        'users.view',
        'notifications.send', // can send dashboard push notifications
    ],
]);
```

## Option 3: Using Artisan Command (Custom)

You can also create a custom artisan command for creating admin users. Here's an example:

```bash
php artisan make:command CreateAdminUser
```

Then implement the command to prompt for name, phone, and password.

## Enabling dashboard notification images

For admin broadcast notifications with images to work correctly, make sure you have the public storage symlink:

```bash
cd backend
php artisan storage:link
```

Uploaded notification images are stored under `storage/app/public/notifications` and served from `public/storage/notifications/...`. The dashboard notifications page lets you upload an image or paste a public image URL, and this URL is sent to the mobile app via FCM.

## Phone Number Format

The system accepts Sudan phone numbers in these formats:
- `0XXXXXXXXX` (10 digits starting with 0) - Recommended
- `249XXXXXXXXX` (international format)
- `9XXXXXXXXX` (without leading 0)

All formats will be normalized to `0XXXXXXXXX` format.

