# Mini ERP — Manufacturing Business Management System

A modular, role-based Enterprise Resource Planning system built with **PHP**, **MySQL**, and **vanilla CSS**. Designed for small-to-medium manufacturing businesses to manage users, products, inventory, procurement, and audit trails through a modern web interface.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

---

## Features

### User Management & Security
- **Role-based access control (RBAC)** — 6 predefined roles (Admin, Purchase User, Sales User, Manufacturing User, Inventory Manager, Business Owner)
- **Module-level permissions** — Granular `can_view`, `can_create`, `can_edit`, `can_delete` per role per module
- **User approval workflow** — New registrations require admin approval via a dedicated `tbl_user_approval_requests` table
- **Brute-force protection** — Account lockout after 5 failed login attempts (15-minute cooldown)
- **Session management** — Secure sessions with configurable timeout, HTTPOnly cookies, and strict SameSite policy
- **CSRF protection** — Token-based form validation on all POST requests

### Inventory & Product Management
- **Product catalog** — Full CRUD with auto-generated product codes (category-based prefixes: `RM-0001`, `FG-0002`, etc.)
- **Category & vendor management** — With referential integrity checks (delete protection when linked to products)
- **Stock tracking** — On-Hand Quantity, Reserved Quantity, and calculated Free-to-Use Quantity
- **Stock status engine** — Automatic classification: In Stock / Low Stock / Out of Stock based on per-product minimum stock levels
- **Manual stock adjustments** — With negative stock prevention (validates against Free-to-Use Quantity)
- **Stock movement log** — Complete audit trail of all inventory changes with before/after quantities
- **Concurrency safety** — `SELECT ... FOR UPDATE` row-level locking during stock updates

### Audit System
- **Comprehensive audit logging** — Every create, update, delete, login, logout, approval, and stock adjustment is recorded
- **Field-level change tracking** — JSON columns store `old_values` and `new_values` for detailed diff history
- **Searchable audit viewer** — Filterable by module, action, user, and date range

### UI / UX
- **Modern design** — White and light blue color scheme with glassmorphism on auth cards
- **Responsive layout** — Sidebar navigation with mobile support
- **Dynamic interactions** — Real-time calculations, live clock, flash messages, micro-animations
- **Google Fonts** — Inter + Outfit typography

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.x (vanilla, no framework) |
| Database | MySQL 8.x (InnoDB, utf8mb4) |
| Frontend | HTML5, Vanilla CSS, Vanilla JavaScript |
| Server | Apache (XAMPP) |
| Icons | Font Awesome 6.5 |
| Fonts | Google Fonts (Inter, Outfit) |

---

## Project Structure

```
MiniERP/
├── admin/                      # Admin-only pages
│   ├── approve_user.php        # Review & approve/reject user registrations
│   ├── audit_log.php           # Searchable audit trail viewer
│   ├── dashboard.php           # Admin dashboard with stats & activity
│   ├── permissions.php         # Role-module permission matrix
│   ├── roles.php               # Role management
│   └── users.php               # User list & management
├── assets/
│   ├── css/style.css           # Complete design system
│   └── js/main.js              # Client-side interactions
├── auth/
│   ├── login.php               # Login with brute-force protection
│   ├── logout.php              # Secure session destruction
│   └── signup.php              # Registration with approval workflow
├── config/
│   └── app.php                 # Constants, session config, security settings
├── dashboard/
│   └── index.php               # Role-based dashboard router
├── includes/
│   ├── audit_log.php           # log_action() helper
│   ├── auth_check.php          # Session validation & permission loading
│   ├── footer.php              # Page footer
│   ├── functions.php           # CSRF, flash messages, sanitization, utilities
│   ├── header.php              # HTML head, navbar, layout wrapper
│   ├── inventory_helpers.php   # Stock helpers, product code generator, audit diff
│   ├── permission_check.php    # RBAC permission functions
│   └── sidebar.php             # Role-aware navigation sidebar
├── modules/
│   ├── inventory/
│   │   ├── adjust.php          # Manual stock adjustments
│   │   ├── index.php           # Inventory overview dashboard
│   │   ├── movements.php       # Stock movement history
│   │   └── vendors.php         # Vendor CRUD
│   └── products/
│       ├── categories.php      # Category management with modals
│       ├── create.php          # New product form
│       ├── delete.php          # Soft-delete (deactivate) product
│       ├── edit.php            # Edit product with field-level audit
│       ├── index.php           # Product catalog with search & filters
│       └── view.php            # Product detail with stock movement history
├── setup/
│   ├── install.php             # Full database installation (8 tables + seed data)
│   └── migrate_inventory.php   # Inventory module migration (4 tables + seed)
├── db.php                      # Database connection
├── index.php                   # Entry point (redirects to login)
└── .gitignore
```

---

## Database Schema

### Core Tables (8)

| Table | Purpose |
|-------|---------|
| `tbl_roles` | System roles (Admin, Purchase User, Sales User, etc.) |
| `tbl_modules` | System modules (Dashboard, Products, Inventory, etc.) |
| `tbl_role_permissions` | Role ↔ Module permission matrix |
| `tbl_users` | User accounts with approval metadata |
| `tbl_user_approval_requests` | Registration/role-change approval workflow |
| `tbl_user_sessions` | Active session tracking |
| `tbl_audit_log` | System-wide event audit trail (JSON old/new values) |
| `tbl_password_resets` | Password recovery tokens |

### Inventory Tables (4)

| Table | Purpose |
|-------|---------|
| `tbl_product_categories` | Product classifications (Raw Materials, Finished Goods, etc.) |
| `tbl_vendors` | Supplier/vendor records |
| `tbl_products` | Product master with pricing, stock levels, procurement config |
| `tbl_stock_movements` | Detailed stock transaction log |

---

## Installation

### Prerequisites
- [XAMPP](https://www.apachefriends.org/) (PHP 8.x + MySQL 8.x + Apache)
- Web browser

### Steps

1. **Clone the repository**
   ```bash
   git clone https://github.com/Keyan786/MINI_ERP.git
   cd MINI_ERP
   ```

2. **Place in XAMPP htdocs**
   ```
   Copy/move the project to: C:\xampp\htdocs\MiniERP\
   ```

3. **Configure database credentials**

   Edit `db.php` and `setup/install.php` with your MySQL credentials:
   ```php
   $db_host = "localhost";
   $db_user = "root";
   $db_pass = "your_password";
   $db_name = "ERP";
   ```

4. **Run the installation script**

   Visit in your browser:
   ```
   http://localhost/MiniERP/setup/install.php
   ```
   This creates the database, all 8 core tables, seeds roles, modules, permissions, and the admin user.

5. **Run the inventory migration**

   Visit or run via CLI:
   ```
   http://localhost/MiniERP/setup/migrate_inventory.php
   ```
   ```bash
   php C:\xampp\htdocs\MiniERP\setup\migrate_inventory.php
   ```

6. **Login**
   ```
   URL:      http://localhost/MiniERP/auth/login.php
   Email:    admin@gmail.com
   Password: 123456
   ```

---

## Default Roles & Permissions

| Role | Dashboard | Products | Sales | Purchase | Manufacturing | BOM | Inventory | User Mgmt | Audit Log |
|------|:---------:|:--------:|:-----:|:--------:|:-------------:|:---:|:---------:|:---------:|:---------:|
| Admin | Full | Full | Full | Full | Full | Full | Full | Full | Full |
| Purchase User | View | View | — | Full | — | — | View | — | — |
| Sales User | View | View | Full | — | — | — | View | — | — |
| Manufacturing User | View | View | — | — | Full | Full | View+ | — | — |
| Inventory Manager | View | View+ | — | View | — | — | Full | — | — |
| Business Owner | View | View | View | View | View | View | View | — | — |

*Full = View + Create + Edit + Delete | View+ = View + Create + Edit*

---

## Key Business Logic

### Stock Quantity Model
```
Free-to-Use Qty = On-Hand Qty − Reserved Qty
```
- **On-Hand Qty**: Physical stock in warehouse
- **Reserved Qty**: Stock committed to active Sales/Manufacturing orders
- **Free-to-Use Qty**: Actual available stock for new transactions

### Stock Status Classification
| Condition | Status | Badge |
|-----------|--------|-------|
| Free-to-Use ≤ 0 | Out of Stock | 🔴 |
| Free-to-Use > 0 AND ≤ Min Level | Low Stock | 🟡 |
| Free-to-Use > Min Level | In Stock | 🟢 |

### Negative Stock Prevention
Stock removals (manual adjustments) are validated against Free-to-Use Quantity, not On-Hand Quantity. This ensures stock committed to active orders cannot be accidentally removed.

---

## Security Features

| Feature | Implementation |
|---------|---------------|
| SQL Injection Prevention | Prepared statements with parameterized queries throughout |
| XSS Prevention | `htmlspecialchars()` output escaping via `e()` helper |
| CSRF Protection | Per-session token with `hash_equals()` validation |
| Password Security | bcrypt hashing with cost factor 12 |
| Brute-Force Protection | Account lockout after 5 failed attempts (15 min) |
| Session Security | HTTPOnly, SameSite=Strict, 30-min timeout, ID regeneration |
| Audit Trail | Every action logged with user, IP, user agent, timestamps |

---

## Upcoming Modules

- **Purchase Management** — Purchase orders, goods receipt, vendor procurement
- **Sales Management** — Sales orders, delivery tracking, invoicing
- **Manufacturing** — Production orders, work orders, BOM consumption
- **Bill of Materials** — Multi-level BOM with component management

---

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/module-name`)
3. Commit your changes (`git commit -m "Add module-name"`)
4. Push to the branch (`git push origin feature/module-name`)
5. Open a Pull Request

---

## License

This project is licensed under the MIT License.
