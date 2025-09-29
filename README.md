# HTML Sitemap Builder for WordPress

A powerful and customizable WordPress plugin to generate HTML sitemaps that automatically stay up-to-date.

## Features

- **Customizable Post Types**: Include or exclude specific post types based on your needs
- **SEO-Friendly**: Automatically skips noindex posts from popular SEO plugins
- **Flexible Sorting Options**: 
  - Alphabetical order
  - Newest to oldest
- **Auto-Update**: Sitemap automatically refreshes when content changes by clearing the cache
- **Easy Integration**: Simple to set up and integrate with any WordPress theme

## Installation

1. Download the plugin files
2. Upload them to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure the sitemap settings in the WordPress admin panel

## Usage

1. After activation, go to Settings > HTML Sitemap in your WordPress dashboard
2. Choose which post types to include in your sitemap
3. Select your preferred sorting method
4. Configure any additional display options
5. Use the shortcode `[html_sitemap]` in any page or post where you want the sitemap to appear

## Configuration Options

- **Post Type Selection**: Choose which content types to display (Posts, Pages, Custom Post Types)
- **Hierarchical Display Selection:** Toggles the parent-child nesting structure for hierarchical post types (like Pages)
- **Maximum Depth:** Sets the maximum number of nesting levels (e.g., parent, child, grandchild) to display in the hierarchical sitemap (up to 50 levels). 
- **Sorting Options:** 
  - Alphabetical
  - Date (Newest/Oldest)
- **Exclusion Options:** 
  - Exclude Posts/Pages (IDs): Manually exclude specific content by entering a list of their IDs.
  - Exclude Noindex Posts: Automatically respects noindex settings from major SEO plugins like Yoast, Rank Math, and SEOPress, removing them from the sitemap.
- **SEO Integration**: Automatically respects noindex settings from major SEO plugins like Yoast, RankMath, and SEOPress. 
- **Cache Control**: Built-in caching system for optimal performance

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher

## Changelog

### 1.0.0
- Initial release
- Basic sitemap functionality
- Post type filtering
- Hierarchical support
- Exclude Posts/Pages
- Exclude Noindex Posts/Pages
- Sorting options
- Cache management

## License

This project is licensed under the GPL v3 - see the [LICENSE](https://github.com/dhruvpandyadp/HTML-Sitemap-Builder/blob/main/LICENSE) file for details.

## Support

For support, feature requests, or bug reports, please [open an issue](https://github.com/dhruvpandyadp/HTML-Sitemap-Builder/issues) on GitHub.

## Author

Created and maintained by [Dhruv Pandya](https://github.com/dhruvpandyadp)

---

Made with ❤️ for the WordPress and SEO community
