# AHX WP Recipe

WordPress plugin for managing recipes as a site-local custom post type. It works on single-site and multisite installations; each site has its own recipes and Bring! settings.

## Features

- Recipe ingredients with quantities, units and steps, plus featured images from the WordPress media library.
- Three display layouts: classic, split view and checklist cooking mode.
- Servings control scales numeric and fractional quantities. Published recipes on public HTTPS sites expose Schema.org Recipe microdata. The Bring! button uses Bring!'s recipe deep link with the recipe's own WordPress permalink and current servings so Bring! can parse the recipe and handle sign-in and native list selection. For unpublished recipes or sites without a public HTTPS URL, it copies scaled ingredients (checked ingredients only if any are checked) and opens Bring! Web for manual entry.
- Import of HTML, DOCX, PDF and TXT files, plus Chefkoch recipe URLs, into editable recipe drafts. Chefkoch structured recipe data is read from schema.org JSON-LD. Only HTTPS URLs on `chefkoch.de` are fetched. PDF extraction requires `pdftotext`; DOCX extraction requires PHP `ZipArchive`. Imported files with recognizable ingredient and instruction headings are split into fields; other text remains in the recipe editor for manual cleanup.
- Chefkoch imports collect candidate recipe images (schema.org image data plus additional images from the page) and show a review step where the user picks which images actually belong to the recipe before the draft is created. The first selected image becomes the featured image, additional ones are stored as a gallery shown below it.
- Optional source field ("Quelle") per recipe, editable in the recipe editor. Chefkoch imports fill it in automatically with the source URL; published recipes show a source link on the frontend.
- Recipe archive at `/rezepte/` and shortcode `[ahx_wp_recipes]` (optional count: `[ahx_wp_recipes number="8"]`).

## Bring! setup

The plugin outputs Schema.org Recipe microdata for the recipe name, yield, image, ingredients and instructions. On published recipes with a public HTTPS permalink, it opens Bring!'s `bringrecipes/deeplink` with that permalink, base portion count and currently requested portion count. Bring! parses the recipe page, handles authentication and presents its native list selection. Drafts and recipes on non-public or non-HTTPS sites fall back to copying ingredients and opening Bring! Web at `https://web.getbring.com/`.

## Multisite

Activate per site or network-activate. Recipes, media associations and Bring! settings are isolated by the active site, as with normal WordPress posts and options.