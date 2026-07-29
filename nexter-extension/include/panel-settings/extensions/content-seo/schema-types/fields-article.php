<?php
/**
 * Field definitions: Article (Schema.org Article).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/article-subtypes.php';

return array(
	array(
		'key'     => '@id',
		'label'   => __( 'ID', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'A unique ID for this schema. You can use the page URL or leave the default value as it is.', 'nexter-extension' ),
		'default' => '%post.url%#article',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Schema Type', 'nexter-extension' ),
		'input'   => 'select_group',
		'tooltip' => __( 'Choose the type of content, such as Article, NewsArticle, or BlogPosting.', 'nexter-extension' ),
		'default' => 'Article',
		'options' => nexter_content_seo_schema_article_subtype_groups(),
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The name or title of the article.', 'nexter-extension' ),
		'default' => '%post.title%',
	),
	array(
		'key'     => 'url',
		'label'   => __( 'URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Canonical URL of the article.', 'nexter-extension' ),
		'default' => '%post.url%',
	),
	array(
		'key'     => 'headline',
		'label'   => __( 'Headline', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter a clear and short headline for the article. Try to keep it under 110 characters for better display in search results.', 'nexter-extension' ),
		'default' => '%post.title%',
	),
	array(
		'key'     => 'description',
		'label'   => __( 'Description', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Write a short summary of the article to help search engines understand the content.', 'nexter-extension' ),
		'default' => '%post.excerpt%',
	),
	array(
		'key'     => 'datePublished',
		'label'   => __( 'Date Published', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter when the article was first published.', 'nexter-extension' ),
		'default' => '%post.date_c%',
	),
	array(
		'key'     => 'dateModified',
		'label'   => __( 'Date Modified', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the last updated date of the article.', 'nexter-extension' ),
		'default' => '%post.modified_date_c%',
	),
	array(
		'key'     => 'commentCount',
		'label'   => __( 'Comment Count', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the total number of comments on the article.', 'nexter-extension' ),
		'default' => '%post.comment_count%',
	),
	array(
		'key'     => 'wordCount',
		'label'   => __( 'Word Count', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the total number of words in the article.', 'nexter-extension' ),
		'default' => '%post.word_count%',
	),
	array(
		'key'     => 'keywords',
		'label'   => __( 'Keywords', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add keywords related to the article, separated by commas.', 'nexter-extension' ),
		'default' => '%post.tags%',
	),
	array(
		'key'     => 'articleSection',
		'label'   => __( 'Sections', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Choose the category or topic the article belongs to (for example: Sports, Business).', 'nexter-extension' ),
		'default' => '%post.categories%',
	),
	array(
		'key'     => 'author',
		'label'   => __( 'Author', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the person who wrote the article.', 'nexter-extension' ),
		'default' => '%schemas.person%',
	),
	array(
		'key'     => 'image',
		'label'   => __( 'Image', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the main image URL for the article.', 'nexter-extension' ),
		'default' => '%post.thumbnail%',
	),
	array(
		'key'     => 'hasPart',
		'label'   => __( 'Subscription And Pay-Walled Content', 'nexter-extension' ),
		'input'   => 'has_part_list',
		'tooltip' => __( 'Use this if your content is behind a paywall or requires a subscription.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'isPartOf',
		'label'   => __( 'Is Part Of', 'nexter-extension' ),
		'input'   => 'editor',
		/* translators: %site.url% is a literal schema-variable token, not a format placeholder — leave it unchanged. */
		'tooltip' => __( 'Reference to the containing WebPage (e.g. %site.url%#webpage).', 'nexter-extension' ),
		// Must match the WebPage node @id (%site.url%#webpage). %schemas.webpage% is not resolved
		// in per-node field replacement, so it produced an empty ref that was pruned as dangling.
		'default' => '%site.url%#webpage',
	),
	array(
		'key'     => 'mainEntityOfPage',
		'label'   => __( 'Main Entity Of Page', 'nexter-extension' ),
		'input'   => 'editor',
		/* translators: %site.url% is a literal schema-variable token, not a format placeholder — leave it unchanged. */
		'tooltip' => __( 'The WebPage this article is the main entity of (e.g. %site.url%#webpage).', 'nexter-extension' ),
		// Link to the WebPage node @id (%site.url%#webpage — same token as isPartOf and the WebPage
		// node itself), which is Google-recommended and resolves in per-node replacement. The old
		// '%post.url%' didn't resolve here (empty → pruned); %schemas.webpage% likewise isn't in the
		// node map, so both siblings now use %site.url%#webpage.
		'default' => '%site.url%#webpage',
	),
	array(
		'key'     => 'publisher',
		'label'   => __( 'Publisher', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the person or organization that published the article.', 'nexter-extension' ),
		'default' => '%schemas.organization%',
	),
	array(
		'key'     => 'mainEntity',
		'label'   => __( 'Main Entity', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Specify the main item being described on the page (usually the article itself).', 'nexter-extension' ),
		'default' => '',
	),
);
