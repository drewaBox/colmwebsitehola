# Frontend

Static COLM registrar portal. Open `index.html` through XAMPP or a local browser.

## Structure

- `index.html`: backward-compatible redirect to the standalone login page.
- `login.html`: central sign-in page and role router.
- `student.html`, `cashier.html`, `registrar.html`, `admin.html`: separate role entry pages using the same shared dashboard UI.
- `css/style.css`: login and portal styles.
- `js/auth.js`: development sign-in, Remember me, and logout behavior.
- `js/navigation.js`: shared sidebar, page switching, search, theme, and profile behavior.
- `assets/images/`: portal logo, registrar seal, and page background.

After sign-in, each account is routed to its matching role page. Logout returns to `login.html`. The role pages share one UI and common CSS/JS; they are separate entry points, not separate feature sets. Demo credentials live in `js/auth.js`; authentication and request/payment/release records remain browser-only and are not production-secure.
