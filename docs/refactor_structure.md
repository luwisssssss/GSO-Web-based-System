# Structure Refactor Notes

## Current Resulting Structure

```text
/app
  /controllers
    /admin
    /auth
    /borrower
    /pages
  /models
  /views
    /layouts
    /partials
/config
/includes
/public
  /assets
    /css
      base.css
      layout.css
      components.css
      /pages
    /images
    /js
/routes
/uploads
/scripts
/docs
/PHPMailer
```

## Compatibility Strategy

The public route folders (`admin`, `auth`, `borrower`, and `pages`) still exist so existing URLs continue to work. Each file in those folders is now a thin wrapper that loads the matching controller under `app/controllers`.

Shared include names are also preserved. Files such as `includes/header.php`, `includes/request_helper.php`, and `includes/mail_helper.php` now forward to their organized locations under `app/views` or `app/models`.

## CSS Breakdown

- `public/assets/css/base.css`: global tokens, reset, typography, focus defaults.
- `public/assets/css/layout.css`: app shell, sidebar, topbar, main content, page shell.
- `public/assets/css/components.css`: cards, tables, buttons, forms, badges, notifications, row actions, facility calendar, shared utilities.
- `public/assets/css/pages/admin.css`: admin role/page baseline styles.
- `public/assets/css/pages/borrower.css`: borrower role/page baseline styles.
- `public/assets/css/pages/login.css`: login screen styles.
- `public/assets/css/pages/auth-pages.css`: forgot password, reset password, verify account styles.
- `public/assets/css/pages/signup.css`: signup page styles.
- `public/assets/css/pages/profile.css`: admin and borrower profile styles.
- `public/assets/css/pages/landing.css`: public landing page styles.
- `public/assets/css/pages/footer.css`: landing/footer styles.
- `public/assets/css/pages/dashboard.css`: dashboard-only motion and legend color classes.
- `public/assets/css/pages/admin-*.css` and `borrower-*.css`: styles extracted from formerly inline page `<style>` blocks.

## Moved Files

- Public page scripts moved to `app/controllers/{admin,auth,borrower,pages}`.
- Shared helper logic moved from `includes` to `app/models`.
- Shared UI partials moved from `includes` to `app/views/layouts` and `app/views/partials`.
- Static assets moved from `assets` to `public/assets`.
- Images moved from `assets/css/img` to `public/assets/images`.

## Notes

Email templates still use inline CSS because email clients require inline styling for reliable rendering. Dashboard chart dimensions still use dynamic inline values because those values are generated from live data.
