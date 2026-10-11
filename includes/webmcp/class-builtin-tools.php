<?php
/**
 * Built-in starter tools for WebMCP Abilities.
 *
 * Registers four practical tools as real WordPress Abilities so they work with
 * both the WebMCP browser API and the MCP Adapter (CLI/API agents).
 *
 * Adapted from WebMCP Abilities 0.8.0 by Code Atlantic (GPL-2.0-or-later).
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the four built-in starter abilities:
 * - wp/search-posts
 * - wp/get-post
 * - wp/get-categories
 * - wp/submit-comment
 */
class Builtin_Tools {

	/** Ability category slug for all WebMCP built-in tools. */
	const CATEGORY = 'webmcp';

	/**
	 * Register the WebMCP ability category.
	 * Hooked into wp_abilities_api_categories_init.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WebMCP', 'mcp-oauth' ),
				'description' => __( 'Tools exposed to AI agents via the WebMCP browser API.', 'mcp-oauth' ),
			)
		);
	}

	/**
	 * Register all built-in tools as WordPress Abilities.
	 * Hooked into wp_abilities_api_init.
	 */
	public function register(): void {
		/**
		 * Filter whether built-in tools are registered.
		 * Set to false to disable all built-in tools.
		 *
		 * @param bool $include Default true.
		 */
		if ( ! apply_filters( 'wmcp_include_builtin_tools', true ) ) {
			return;
		}

		$this->register_search_posts();
		$this->register_get_post();
		$this->register_get_categories();
		$this->register_submit_comment();
	}

	// -------------------------------------------------------------------------
	// Tool: wp/search-posts
	// -------------------------------------------------------------------------

	/**
	 * Register the wp/search-posts tool.
	 */
	private function register_search_posts(): void {
		wp_register_ability(
			'wp/search-posts',
			array(
				'label'               => __( 'Search Posts', 'mcp-oauth' ),
				'description'         => __( 'Search published posts by keyword. Returns titles, excerpts, and URLs.', 'mcp-oauth' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => __( 'The search keyword or phrase.', 'mcp-oauth' ),
						),
						'count' => array(
							'type'        => 'integer',
							'description' => __( 'Number of results to return (1–50).', 'mcp-oauth' ),
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 50,
						),
					),
					'required'   => array( 'query' ),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array( 'type' => 'integer' ),
							'title'   => array( 'type' => 'string' ),
							'excerpt' => array( 'type' => 'string' ),
							'url'     => array( 'type' => 'string' ),
							'date'    => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_search_posts' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'wmcp_visibility' => 'public',
					'annotations'     => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute wp/search-posts.
	 *
	 * @param array $input Validated input from the agent.
	 * @return array|\WP_Error
	 */
	public function execute_search_posts( array $input ) {
		$query = sanitize_text_field( $input['query'] ?? '' );
		$count = max( 1, min( 50, (int) ( $input['count'] ?? 10 ) ) );

		if ( '' === $query ) {
			return new \WP_Error( 'invalid_query', __( 'Search query is required.', 'mcp-oauth' ) );
		}

		$posts = get_posts(
			array(
				'post_status'    => 'publish',
				'posts_per_page' => $count,
				's'              => $query,
			)
		);

		return array_map(
			static function ( \WP_Post $post ): array {
				return array(
					'id'      => $post->ID,
					'title'   => get_the_title( $post ),
					'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ),
					'url'     => get_permalink( $post ),
					'date'    => $post->post_date,
				);
			},
			$posts
		);
	}

	// -------------------------------------------------------------------------
	// Tool: wp/get-post
	// -------------------------------------------------------------------------

	/**
	 * Register the wp/get-post tool.
	 */
	private function register_get_post(): void {
		wp_register_ability(
			'wp/get-post',
			array(
				'label'               => __( 'Get Post', 'mcp-oauth' ),
				'description'         => __( 'Retrieve a single post by its ID or slug. Returns the full post content, categories, tags, and metadata.', 'mcp-oauth' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array(
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'mcp-oauth' ),
						),
						'slug' => array(
							'type'        => 'string',
							'description' => __( 'Post slug (URL name).', 'mcp-oauth' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'title'      => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'excerpt'    => array( 'type' => 'string' ),
						'date'       => array( 'type' => 'string' ),
						'author'     => array( 'type' => 'string' ),
						'url'        => array( 'type' => 'string' ),
						'categories' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'tags'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_post' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'wmcp_visibility' => 'public',
					'annotations'     => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute wp/get-post.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public function execute_get_post( array $input ) {
		$post = null;

		if ( ! empty( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
		} elseif ( ! empty( $input['slug'] ) ) {
			$posts = get_posts(
				array(
					'name'        => sanitize_title( $input['slug'] ),
					'post_status' => 'publish',
					'numberposts' => 1,
				)
			);
			$post  = $posts[0] ?? null;
		}

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'not_found', __( 'Post not found.', 'mcp-oauth' ), array( 'status' => 404 ) );
		}

		// Only return published posts to unauthenticated users.
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			return new \WP_Error( 'not_found', __( 'Post not found.', 'mcp-oauth' ), array( 'status' => 404 ) );
		}

		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		$author     = get_the_author_meta( 'display_name', (int) $post->post_author );

		return array(
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'content'    => wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
			'excerpt'    => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'date'       => $post->post_date,
			'author'     => $author,
			'url'        => get_permalink( $post ),
			'categories' => is_array( $categories ) ? $categories : array(),
			'tags'       => is_array( $tags ) ? $tags : array(),
		);
	}

	// -------------------------------------------------------------------------
	// Tool: wp/get-categories
	// -------------------------------------------------------------------------

	/**
	 * Register the wp/get-categories tool.
	 */
	private function register_get_categories(): void {
		wp_register_ability(
			'wp/get-categories',
			array(
				'label'               => __( 'Get Categories', 'mcp-oauth' ),
				'description'         => __( 'List all post categories with their names, descriptions, and post counts.', 'mcp-oauth' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array( 'type' => 'integer' ),
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'count'       => array( 'type' => 'integer' ),
							'url'         => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_categories' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'wmcp_visibility' => 'public',
					'annotations'     => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute wp/get-categories.
	 *
	 * @param array $input Validated input (unused).
	 * @return array
	 */
	public function execute_get_categories( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by callback signature.
		$categories = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $categories ) || ! is_array( $categories ) ) {
			return array();
		}

		return array_map(
			static function ( \WP_Term $cat ): array {
				return array(
					'id'          => $cat->term_id,
					'name'        => $cat->name,
					'slug'        => $cat->slug,
					'description' => wp_strip_all_tags( $cat->description ),
					'count'       => (int) $cat->count,
					'url'         => get_category_link( $cat->term_id ),
				);
			},
			$categories
		);
	}

	// -------------------------------------------------------------------------
	// Tool: wp/submit-comment
	// -------------------------------------------------------------------------

	/**
	 * Register the wp/submit-comment tool.
	 */
	private function register_submit_comment(): void {
		wp_register_ability(
			'wp/submit-comment',
			array(
				'label'               => __( 'Submit Comment', 'mcp-oauth' ),
				'description'         => __( 'Submit a comment on a post. Respects WordPress comment settings including open/closed comments and login requirements.', 'mcp-oauth' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post to comment on.', 'mcp-oauth' ),
						),
						'content'      => array(
							'type'        => 'string',
							'description' => __( 'Comment text.', 'mcp-oauth' ),
						),
						'author_name'  => array(
							'type'        => 'string',
							'description' => __( 'Commenter name (optional for logged-in users).', 'mcp-oauth' ),
						),
						'author_email' => array(
							'type'        => 'string',
							'description' => __( 'Commenter email (optional for logged-in users).', 'mcp-oauth' ),
						),
					),
					'required'   => array( 'post_id', 'content' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array( 'type' => 'integer' ),
						'status'     => array(
							'type' => 'string',
							'enum' => array( 'approved', 'pending', 'spam' ),
						),
						'message'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_submit_comment' ),
				'permission_callback' => array( $this, 'can_submit_comment' ),
				'meta'                => array(
					'wmcp_visibility' => 'public',
					'annotations'     => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Permission check for wp/submit-comment.
	 * Respects the site's "Anyone can register" and "Users must be logged in to comment" settings.
	 */
	public function can_submit_comment(): bool {
		// If comments require login, user must be logged in.
		if ( get_option( 'comment_registration' ) && ! is_user_logged_in() ) {
			return false;
		}
		return true;
	}

	/**
	 * Execute wp/submit-comment.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public function execute_submit_comment( array $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$content = sanitize_textarea_field( $input['content'] ?? '' );

		if ( ! $post_id || '' === $content ) {
			return new \WP_Error( 'invalid_input', __( 'post_id and content are required.', 'mcp-oauth' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'not_found', __( 'Post not found.', 'mcp-oauth' ) );
		}

		if ( ! comments_open( $post_id ) ) {
			return new \WP_Error( 'comments_closed', __( 'Comments are closed on this post.', 'mcp-oauth' ) );
		}

		$comment_data = array(
			'comment_post_ID'  => $post_id,
			'comment_content'  => $content,
			'comment_approved' => 0, // Let WordPress approval workflow decide.
		);

		// Fill in author info from logged-in user or from input.
		if ( is_user_logged_in() ) {
			$user                                 = wp_get_current_user();
			$comment_data['user_id']              = $user->ID;
			$comment_data['comment_author']       = $user->display_name;
			$comment_data['comment_author_email'] = $user->user_email;
			$comment_data['comment_author_url']   = $user->user_url;
		} else {
			$comment_data['comment_author']       = sanitize_text_field( $input['author_name'] ?? '' );
			$comment_data['comment_author_email'] = sanitize_email( $input['author_email'] ?? '' );
		}

		$comment_id = wp_new_comment( $comment_data, true );

		if ( is_wp_error( $comment_id ) ) {
			return $comment_id;
		}

		$comment = get_comment( $comment_id );
		$status = '1' === (string) $comment->comment_approved ? 'approved' : ( 'spam' === $comment->comment_approved ? 'spam' : 'pending' );

		return array(
			'comment_id' => (int) $comment_id,
			'status'     => $status,
			'message'    => 'approved' === $status
				? __( 'Comment posted successfully.', 'mcp-oauth' )
				: __( 'Comment submitted and is awaiting moderation.', 'mcp-oauth' ),
		);
	}
}
