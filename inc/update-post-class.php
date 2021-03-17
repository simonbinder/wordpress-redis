<?php

namespace PurpleRedis\Inc;

use PurpleDsHub\Inc\Interfaces\Hooks_Interface;
use \PurpleDsHub\Inc\Utilities\Torque_Urls;
use const PurpleDsHub\Inc\Api\PURPLE_IN_ISSUES;

if ( ! class_exists( 'Update_Post' ) ) {
	class Update_Post implements Hooks_Interface {

		/**
		 * Component's handle.
		 */
		const HANDLE = 'update-post';

		/**
		 * Connection to Redis db.
		 *
		 * @var $connection
		 */
		private $connection;

		/**
		 * Update_Post constructor.
		 *
		 * @param $connection
		 */
		public function __construct( $connection ) {
			$this->connection = $connection;
		}

		/**
		 * Initialize all hooks.
		 */
		public function init_hooks() {
			add_action( 'save_post', array( $this, 'save_in_db' ), 10, 3 );
			add_action( 'delete_post', array( $this, 'delete_from_db' ) );
			add_action( 'wp_loaded', array( $this, 'update_all_posts' ) );
		}

		/**
		 * Update all posts.
		 */
		public function update_all_posts() {
			$args      = array(
				'numberposts' => -1,
				'post_status' => 'any',
				'post_type'   => get_post_types( '', 'names' ),
			);
			$all_posts = get_posts( $args );
			foreach ( $all_posts as $single_post ) {
				$this->save_in_db( $single_post->ID, $single_post, null );
			}
		}

		/**
		 * Delete post from db.
		 *
		 * @param int $postid current post id.
		 */
		public function delete_from_db( int $postid ) {
			$mongo_posts = $this->connection->posts;
			$mongo_posts->deleteOne(
				array( 'postId' => $postid )
			);
			$mongo_posts->deleteMany(
				array( 'post_parent' => $postid )
			);
		}

		/**
		 * Filter out null blocks.
		 *
		 * @param array $block block that gets filtered.
		 * @return bool
		 */
		private function filter_blocks( $block ) {
			return $block['blockName'] !== null;
		}

		/**
		 * Save post in redis db.
		 *
		 * @param int      $post_id current post id
		 * @param \WP_Post $post current post.
		 * @param bool     $update Whether this is an existing post being updated.
		 */
		public function save_in_db( int $post_id, \WP_Post $post, bool $update ) {
			if ( has_blocks( $post->post_content ) ) {
				$blocks          = parse_blocks( $post->post_content );
				$blocks_filtered = array_filter( $blocks, array( $this, 'filter_blocks' ) );
				foreach ( $blocks_filtered as $key => $block ) {
					$text                                        = wp_strip_all_tags( $block['innerHTML'] );
					$blocks_filtered[ $key ]['attrs']['content'] = $text;
				}
				$post_categories = wp_get_post_categories( $post_id );
				$post_meta       = get_post_meta( $post_id, '', true );
				$issues          = $this->retrieve_issues( $post_id );
				$articles        = $this->retrieve_articles( $post_id );
				$custom_fields   = $this->retrieve_custom_fields( $post_meta );
				$author_id       = get_post_field( 'post_author', $post_id );
				$term_list       = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'all' ) );
				$term_ids        = array();
				foreach ( $term_list as $term ) {
					array_push(
						$term_ids,
						$term->term_id
					);
				}
				$blocks_array = array_values( $blocks_filtered );
				$ids          = array();
				foreach ( $blocks_array as $value ) {
					$ids[] = $value['attrs']['purpleId'];
				}
				$this->connection->hMSet(
					'post:' . $post_id,
					array(
						'postId'            => $post_id,
						'author'            => intval( $author_id ),
						'post_title'        => $post->post_title,
						'post_status'       => $post->post_status,
						'post_parent'       => $post->post_parent,
						'comment_status'    => $post->comment_status,
						'post_name'         => $post->post_name,
						'post_modified'     => $post->post_modified,
						'post_modified_gmt' => $post->post_modified_gmt,
						'guid'              => $post->guid,
						'post_type'         => $post->post_type,
						'purpleIssue'       => $issues[0]->ID,
						'postContent'       => wp_json_encode( $blocks_array ),
					),
				);
				if ( array_column( $articles, 'ID' ) ) {
					$this->connection->hMSet(
						'purpleIssueArticles:' . $post_id,
						array_column( $articles, 'ID' )
					);
				}
				$this->connection->hMSet(
					'categories:' . $post_id,
					$post_categories
				);
				$this->connection->hMSet(
					'tags:' . $post_id,
					$term_ids
				);
				$this->connection->hMSet(
					'content:' . $post_id,
					$ids
				);
				$this->connection->set(
					'custom_fields:' . $post_id,
					wp_json_encode( $custom_fields )
				);
				$this->save_block_fields( $blocks_array );
				$this->update_categories();
				$this->update_users();
				$this->update_tags();
			}
		}

		/**
		 * Retrieve issues.
		 *
		 * @param int $post_id current post id.
		 */
		private function retrieve_issues( $post_id ) {
			$issues = get_post_meta( $post_id, PURPLE_IN_ISSUES, true ) ?: array();
			return array_map(
				function ( $issue_id ) {
					return get_post( $issue_id );
				},
				$issues
			);
		}

		/**
		 * Retrieve articles.
		 *
		 * @param int $post_id current post id.
		 */
		private function retrieve_articles( $post_id ) {
			$articles = get_post_meta( $post_id, 'purple_issue_articles', true );
			return array_map(
				function ( $article_id ) {
					$post                      = get_post( $article_id );
					$post->{'permalink'}       = get_permalink( $article_id );
					$post->{'author_name'}     = get_the_author_meta( 'display_name', $post->post_author );
					$post->{'article_options'} = get_post_meta( $article_id, 'purple_content_options', true );

					return $post;
				},
				$articles
			);
		}

		/**
		 * Retrieve all custom fields.
		 *
		 * @param array $post_meta all post meta.
		 */
		private function retrieve_custom_fields( $post_meta ) {
			$custom_fields = array();
			foreach ( $post_meta as $meta_key => $meta_value ) {
				if ( Torque_Urls::starts_with( $meta_key, 'purple_custom_meta_' ) ) {
					$stripped_key = str_replace( 'purple_custom_meta_', '', $meta_key );
					array_push(
						$custom_fields,
						array(
							'field' => $stripped_key,
							'value' => $meta_value[0],
						)
					);
				}
			}
			return $custom_fields;
		}

		/**
		 * Save post content fields in db.
		 *
		 * @param array $blocks_array array of blocks belonging to one post.
		 */
		private function save_block_fields( array $blocks_array ) {
			foreach ( $blocks_array as $value ) {
				$this->connection->hmSet(
					'block:' . $value['attrs']['purpleId'],
					array(
						'blockName'    => $value['blockName'],
						'innerHTML'    => $value['innerHTML'],
						'innerContent' => $value['innerContent'][0],
					)
				);
				$this->connection->hmSet(
					'blockattrs:' . $value['attrs']['purpleId'],
					$value['attrs']
				);
			}
		}

		/**
		 * Update all users related to a post.
		 */
		private function update_users() {
			$users = get_users();
			foreach ( $users as $user ) {
				$this->connection->hmSet(
					'user:' . $user->ID,
					array(
						'user_id'      => $user->ID,
						'login'        => $user->data->user_login,
						'display_name' => $user->data->display_name,
						'email'        => $user->data->user_email,
					),
				);
			}
		}

		/**
		 * Update all tags related to a post.
		 */
		private function update_tags() {
			$tags = get_tags();
			foreach ( $tags as $tag ) {
				$this->connection->hmSet(
					'tag:' . $tag->term_id,
					array(
						'term_id' => $tag->term_id,
						'name'    => $tag->name,
						'slug'    => $tag->slug,
					),
				);
			}
		}

		/**
		 * Update all categories related to a post.
		 */
		private function update_categories() {
			$categories = get_categories();
			foreach ( $categories as $category ) {
				$this->connection->hmSet(
					'category:' . $category->term_id,
					array(
						'term_id' => $category->term_id,
						'name'    => $category->name,
					),
				);
			}
		}
	}
}
