<?php

class Cloudinary_WP_Integration {

	private static $instance;

	/**
	 * @return Cloudinary_WP_Integration
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new Cloudinary_WP_Integration();
		}
		return self::$instance;
	}

	public function __construct() {
	}

	public function setup() {
		$this->register_hooks();
	}

	public function register_hooks() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_cloudinary_data' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( $this, 'get_attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 'image_downsize' ), 10, 3 );

		// Filter images created on the fly.
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'wp_get_attachment_image_attributes' ), 10, 3 );

		// Replace the default WordPress content filter with our own.
		remove_filter( 'the_content', 'wp_make_content_images_responsive' );
		add_filter( 'the_content', array( $this, 'make_content_images_responsive',  ) );
	}

	public function generate_cloudinary_data( $metadata, $id ) {
		// Bail early if we don't have a file path to work with.
		if ( ! isset( $metadata['file'] ) ) {
			return $metadata;
		}

		$uploads = wp_get_upload_dir();
		$filepath = trailingslashit( $uploads['basedir'] ) . $metadata['file'];

		// Try mirroring the image on Cloudinary, and buld custom metadata from the response.
		if ( $data = $this->handle_upload( $filepath ) ) {
			if ( isset( $data['secure_url'] ) ) {

			}

			$metadata['cloudinary_data'] = array(
				'public_id'	=> $data['public_id'],
				'width'			=> $data['width'],
				'height'		 => $data['height'],
				'bytes'			=> $data['bytes'],
				'url'				=> $data['url'],
				'secure_url' => $data['secure_url'],
			);

			foreach ( $data['responsive_breakpoints'][0]['breakpoints'] as $size ) {
				$metadata['cloudinary_data']['sizes'][$size['width'] ] = $size;
			}
		};

		return $metadata;
	}

	public function handle_upload( $file ) {
		$data = false;

		if ( is_callable( array( '\Cloudinary\Uploader', 'upload' ) ) ) {
			$api_args = array(
				'responsive_breakpoints' => array(
					array(
						'create_derived' => false,
						'bytes_step'		 => 20000,
						'min_width'			=> 200,
						'max_width'			=> 1000,
						'max_images'		 => 20,
					),
				),
				'use_filename' => true,
			);

			$response =	\Cloudinary\Uploader::upload( $file, $api_args );

			// Check for a valid response before returning Cloudinary data.
			$data = isset( $response['public_id'] ) ? $response : false;
		}

		return $data;
	}

	public function get_attachment_url( $url, $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( isset( $metadata['cloudinary_data']['secure_url'] ) ) {
			$url = $metadata['cloudinary_data']['secure_url'];
		}

		return $url;
	}

	public function image_downsize( $downsize, $attachment_id, $size ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( isset( $metadata['cloudinary_data']['secure_url'] ) ) {
			$sizes = $this->get_wordpress_image_size_data( $size );

			// If we found size data, let's figure out our own downsize attributes.
			if ( is_string( $size ) && isset( $sizes[ $size ] ) &&
				( $sizes[ $size ]['width'] <= $metadata['cloudinary_data']['width'] ) &&
				( $sizes[ $size ]['height'] <= $metadata['cloudinary_data']['height'] ) ) {

				$width = $sizes[ $size ]['width'];
				$height = $sizes[ $size ]['height'];

				$dims = image_resize_dimensions( $metadata['width'], $metadata['height'], $sizes[ $size ]['width'], $sizes[ $size ]['height'], $sizes[ $size ]['crop'] );

				if ( $dims ) {
					$width = $dims[4];
					$height = $dims[5];
				}

				$crop = ( $sizes[ $size ]['crop'] ) ? 'c_lfill' : 'c_limit';

				$url_params = "w_$width,h_$height,$crop";

				$downsize = array(
					str_replace( '/image/upload', '/image/upload/' . $url_params, $metadata['cloudinary_data']['secure_url'] ),
					$width,
					$height,
					true,
				);
			} elseif ( is_array( $size ) ) {
				$downsize = array(
					str_replace( '/image/upload', "/image/upload/w_$size[0],h_$size[1],c_limit", $metadata['cloudinary_data']['secure_url'] ),
					$size[0],
					$size[1],
					true,
				);
			}
		}

		return $downsize;
	}

	private function get_wordpress_image_size_data( $size = null ) {
		global $_wp_additional_image_sizes;

		$sizes = array();

		foreach ( get_intermediate_image_sizes() as $s ) {
			// Skip over sizes we're not returning.
			if ( $size && $size != $s ) {
				continue;
			}

			$sizes[$s] = array( 'width' => '', 'height' => '', 'crop' => false );

			// Set the width.
			if ( isset( $_wp_additional_image_sizes[$s]['width'] ) ) {
				$sizes[$s]['width'] = intval( $_wp_additional_image_sizes[$s]['width'] ); // For theme-added sizes
			} else {
				$sizes[$s]['width'] = get_option( "{$s}_size_w" ); // For default sizes set in options
			}

			// Set the height.
			if ( isset( $_wp_additional_image_sizes[$s]['height'] ) ) {
				$sizes[$s]['height'] = intval( $_wp_additional_image_sizes[$s]['height'] ); // For theme-added sizes
			} else {
				$sizes[$s]['height'] = get_option( "{$s}_size_h" ); // For default sizes set in options
			}

			// Set the crop value.
			if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) ) {
				$sizes[$s]['crop'] = $_wp_additional_image_sizes[$s]['crop']; // For theme-added sizes
			} else {
				$sizes[$s]['crop'] = get_option( "{$s}_crop" ); // For default sizes set in options
			}
		}

		return $sizes;
	}

	public function wp_get_attachment_image_attributes( $attr, $attachment, $size ) {
		$metadata = wp_get_attachment_metadata( $attachment->ID );

		if ( is_string( $size ) ) {
			if ( 'full' === $size ) {
				$width = $attachment['width'];
				$height = $attachment['height'];
			} elseif ( $data = $this->get_wordpress_image_size_data( $size ) ) {
				// Bail early if this is a cropped image size.
				if ( $data[$size]['crop'] ) {
					return $attr;
				}

				$width = $data[$size]['width'];
				$height = $data[$size]['height'];
			}
		} elseif ( is_array( $size ) ) {
			list( $width, $height ) = $size;
		}

		if ( isset( $metadata['cloudinary_data']['sizes'] ) ) {
			$sources = array();

			foreach( $metadata['cloudinary_data']['sizes'] as $s ) {
				$sources[ $s['width'] ] = array(
					'url'        => $s['secure_url'],
					'descriptor' => 'w',
					'value'      => $s['width'],
				);
			}

			$srcset = '';

			foreach ( $sources as $source ) {
				$srcset .= str_replace( ' ', '%20', $source['url'] ) . ' ' . $source['value'] . $source['descriptor'] . ', ';
			}

			if ( ! empty( $srcset ) ) {
				$attr['srcset'] = rtrim( $srcset, ', ' );
				$sizes = sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $width );

				// Convert named size to dimension array to workaround TwentySixteen bug.
				$size = array($width, $height);
				$attr['sizes'] = apply_filters( 'wp_calculate_image_sizes', $sizes, $size, $attr['src'], $metadata, $attachment->ID );
			}
		}

		return $attr;
	}

	public function make_content_images_responsive( $content ) {
		if ( ! preg_match_all( '/<img [^>]+>/', $content, $matches ) ) {
			return $content;
		}

		$selected_images = $attachment_ids = array();

		foreach( $matches[0] as $image ) {
			if ( false === strpos( $image, ' srcset=' ) && preg_match( '/wp-image-([0-9]+)/i', $image, $class_id ) &&
				( $attachment_id = absint( $class_id[1] ) ) ) {

				/*
				 * If exactly the same image tag is used more than once, overwrite it.
				 * All identical tags will be replaced later with 'str_replace()'.
				 */
				$selected_images[ $image ] = $attachment_id;
				// Overwrite the ID when the same image is included more than once.
				$attachment_ids[ $attachment_id ] = true;
			}
		}

		if ( count( $attachment_ids ) > 1 ) {
			/*
			 * Warm object cache for use with 'get_post_meta()'.
			 *
			 * To avoid making a database call for each image, a single query
			 * warms the object cache with the meta information for all images.
			 */
			update_meta_cache( 'post', array_keys( $attachment_ids ) );
		}

		foreach ( $selected_images as $image => $attachment_id ) {
			$image_meta = wp_get_attachment_metadata( $attachment_id );
			$content = str_replace( $image, $this->add_srcset_and_sizes( $image, $image_meta, $attachment_id ), $content );
		}

		return $content;
	}

	public function add_srcset_and_sizes( $image, $image_meta, $attachment_id ) {
		if ( isset( $image_meta['cloudinary_data']['sizes'] ) ) {
			// See if our filename is in the URL string.
			if ( false !== strpos( $image, wp_basename( $image_meta['cloudinary_data']['url'] ) ) && false === strpos( $image, 'c_lfill') ) {
				$src = preg_match( '/src="([^"]+)"/', $image, $match_src ) ? $match_src[1] : '';
				$width  = preg_match( '/ width="([0-9]+)"/',  $image, $match_width  ) ? (int) $match_width[1]  : 0;
				$height = preg_match( '/ height="([0-9]+)"/', $image, $match_height ) ? (int) $match_height[1] : 0;

				foreach( $image_meta['cloudinary_data']['sizes'] as $s ) {
					$sources[ $s['width'] ] = array(
						'url'        => $s['secure_url'],
						'descriptor' => 'w',
						'value'      => $s['width'],
					);
				}

				$srcset = '';

				foreach ( $sources as $source ) {
					$srcset .= str_replace( ' ', '%20', $source['url'] ) . ' ' . $source['value'] . $source['descriptor'] . ', ';
				}

				if ( ! empty( $srcset ) ) {
					$srcset = rtrim( $srcset, ', ' );
					$sizes = sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $width );

					// Convert named size to dimension array to workaround TwentySixteen bug.
					$size = array($width, $height);
					$sizes = apply_filters( 'wp_calculate_image_sizes', $sizes, $size, $src, $image_meta, $attachment_id );
				}

				$image = preg_replace( '/src="([^"]+)"/', 'src="$1" srcset="' . $srcset . '" sizes="' . $sizes .'"', $image );
			}
		}

		return $image;
	}

}
