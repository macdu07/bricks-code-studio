# Bricks Code Studio

Bricks Code Studio is an experimental, docked code workspace for Bricks Builder 2.4+. It lets you manage SCSS, CSS, JavaScript, and a protected HTML representation of the current Bricks document without leaving the builder.

The plugin is independent of Bricks and your child theme. Source files are stored in WordPress uploads, compiled on the server, and published as versioned external assets.

## Current features

### Code workspace

- Separate **Global** and **Document** workspaces.
- Real `.scss`, `.css`, and `.js` files organized in folders.
- Multiple stylesheet and JavaScript entry points.
- SCSS partials: filenames beginning with `_` are importable but are not published as independent entries.
- File creation from each workspace folder, plus VS Code-style context menus for create, rename, and delete actions.
- CodeMirror editor with syntax highlighting, autocomplete, diagnostics, automatic bracket/tag closing, formatting, and editable color swatches.
- `Ctrl+S` / `Cmd+S` integration with the native Bricks save shortcut.

### CSS and SCSS builds

- Server-side SCSS compilation with workspace-only import resolution.
- Plain CSS entries that do not depend on Sass.
- **Expanded** or **Minified** published CSS, configurable independently for each workspace.
- Optional source maps.
- Atomic, hash-versioned builds with cleanup of obsolete versions.
- The last valid published build remains active when a new compilation fails.
- A read-only CSS tab that restores the compiled output after the builder reloads.

The CSS shown in Code Studio, used for diagnostics, and sent to Bricks synchronization remains expanded. Minification only affects the external file published to the frontend.

### Preview and frontend loading

- Toggleable **Live** preview for CSS and SCSS while typing.
- Preview CSS is injected temporarily into the Bricks canvas and is never used as the production delivery method.
- Published CSS is loaded through WordPress as an external stylesheet.
- Global assets load first; assets for the current document and active Bricks templates load afterward.
- Published JavaScript loads in the footer with `defer`.
- JavaScript drafts run only when **Run JS** is selected, which reloads the canvas to prevent duplicated listeners.

### Bricks synchronization

- Optional automatic synchronization of compatible CSS classes and variables when a stylesheet is saved.
- Manual **Sync Bricks** action for reviewing and applying synchronization explicitly.
- Existing same-named Bricks resources can be linked once and updated on later saves.
- Resources not owned or linked by Code Studio are not silently overwritten or deleted.
- Bricks visual controls and the canvas are refreshed after a successful synchronization when the active Bricks version supports it.

Compiled CSS works independently of synchronization. Syncing is only needed when you also want compatible values represented in Bricks' native global classes, variables, and visual controls.

### Experimental HTML structure editor

- Generates a protected HTML projection of the current Bricks element tree.
- Preserves stable `data-bcs-id` identifiers and known global class relationships.
- Supports compatible ordering, nesting, deletion, tags, classes, and static content changes.
- New compatible markup is converted through Bricks' HTML/CSS conversion ability.
- Complex elements, components, query loops, forms, dynamic data, conditions, and interactions are represented as protected atomic nodes.
- **Preview structure** renders a proposed tree without saving it.
- Saving HTML previews the diff, asks for confirmation before destructive changes, creates a Bricks revision, applies the structure, and exposes Undo.
- Stale HTML drafts are rejected if the underlying Bricks tree changed after the projection was generated.

The HTML editor is not intended to provide arbitrary HTML round-tripping. It only applies changes that can be safely merged back into a Bricks structure.

## Global vs. Document

| Scope | Used for | Frontend order |
| --- | --- | --- |
| **Global** | Site-wide styles and JavaScript | Loaded first on every frontend page |
| **Document** | The current page, post, or Bricks template | Loaded after Global when that document or template is active |

Use Global for shared tokens, utilities, components, and common behavior. Use Document for code that only belongs to the page or template currently open in Bricks.

## Build controls

The **Build** menu applies to the currently selected scope:

- **Expanded** keeps the published CSS readable.
- **Minified** removes unnecessary whitespace for a smaller production asset.
- **Source maps** can be enabled for debugging or disabled to avoid publishing `.map` files.

Changing a build setting republishes the last saved source immediately. If the active editor contains unsaved changes, the setting is stored and applied on the next save.

## Workspace storage

Code Studio does not import or modify child-theme files. Its workspace is created under:

```text
wp-content/uploads/bricks-code-studio/{siteId}/
├── src/
│   ├── global/
│   │   ├── scss/
│   │   ├── css/
│   │   ├── js/
│   │   └── manifest.json
│   └── documents/{postId}/
│       ├── scss/
│       ├── css/
│       ├── js/
│       └── manifest.json
└── dist/
```

New scopes include `scss/main.scss` and `js/main.js`. Every non-partial `.scss`, `.css`, or `.js` file is treated as an entry and contributes to its scope's published bundle.

## Requirements

- WordPress 6.9 or newer.
- Bricks Builder 2.4 or newer.
- PHP 7.4 or newer.
- A user who can access the Bricks builder and has Bricks **Execute Code** permission.
- A writable WordPress uploads directory.

The plugin deactivates itself with an admin notice when the WordPress or Bricks requirements are not met.

## Installation

### From a prepared release

1. Copy the plugin to `wp-content/plugins/bricks-code-studio`.
2. Activate **Bricks Code Studio** in WordPress.
3. Open a page or template in Bricks Builder.
4. Use the Code Studio panel docked at the bottom of the builder.

### From source

```bash
git clone https://github.com/macdu07/bricks-code-studio.git
cd bricks-code-studio
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

Then place or symlink the repository at `wp-content/plugins/bricks-code-studio` and activate it in WordPress.

## Development

Install development dependencies:

```bash
composer install
npm install
```

Build the browser assets:

```bash
npm run build
```

Run the JavaScript and PHP test suites:

```bash
npm test
vendor/bin/phpunit --configuration phpunit.xml.dist
```

## Security model

- REST requests require a valid WordPress REST nonce and Bricks code-execution permissions.
- Files are limited to `.scss`, `.css`, and `.js` and to 1 MB each.
- Path traversal, external paths, hidden paths, symbolic links, PHP files, and unsupported extensions are rejected.
- SCSS imports are restricted to the workspace; remote imports are not downloaded.
- File writes and published builds are atomic.
- Optimistic content hashes prevent silently overwriting a file changed elsewhere.
- Builds containing compilation errors are never published.

## Status and limitations

Current plugin version: **0.2.0**.

Bricks Code Studio is under active development. The HTML structure editor and the reactive Bricks control adapter are experimental and version-gated. Back up important sites and test new releases in staging before using them in production.

Bug reports and feature requests are welcome in [GitHub Issues](https://github.com/macdu07/bricks-code-studio/issues).

## License

GPL-2.0-or-later.
