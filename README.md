# Bricks Code Studio

Bricks Code Studio adds a docked code workspace to Bricks Builder 2.4+ for editing SCSS, CSS, JavaScript, and an experimental protected HTML structure view without leaving the builder.

## Features

- Global and document-scoped workspaces.
- Real `.scss`, `.css`, and `.js` files with multiple entry points.
- Server-side SCSS compilation and versioned frontend assets.
- Live CSS preview in the Bricks canvas.
- Controlled JavaScript preview.
- CSS class and variable synchronization with Bricks.
- Automatic synchronization and reactive refresh of active Bricks controls on save.
- CodeMirror editing with autocomplete and diagnostics.
- `Ctrl+S` / `Cmd+S` integration with the native Bricks save shortcut.
- Experimental protected HTML structure projection, preview, diff, apply, and undo.

## Requirements

- WordPress 6.9 or newer.
- Bricks Builder 2.4 or newer.
- PHP 7.4 or newer.
- A user with Bricks builder and Execute Code permissions.

## Development setup

```bash
composer install
npm install
npm run build
```

Run the unit tests with:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist
```

## Installation

1. Clone or copy this repository to `wp-content/plugins/bricks-code-studio`.
2. Run `composer install --no-dev --optimize-autoloader` inside the plugin directory.
3. Activate **Bricks Code Studio** in WordPress.
4. Open a page in Bricks Builder and use the docked panel at the bottom.

The workspace is stored under WordPress uploads. Existing child-theme files are not imported or modified.

## Status

This project is under active development. The HTML structure editor and the Bricks 2.4 reactive control adapter are experimental and version-gated.

