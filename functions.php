<?php
/*Agregar Banner para categorias*/
add_action('storefront_before_content', 'add_banner');
function add_banner()
{

	if (is_product_category()) {
		global $wp_query;
		$obj = $wp_query->get_queried_object();
		$objid = $obj->term_id;

		$url_img_desk = get_woocommerce_term_meta($obj->term_id, 'banner_escritorio', true);
		$image_desk = wp_get_attachment_url($url_img_desk);

		$url_img_movil = get_woocommerce_term_meta($obj->term_id, 'banner_movil', true);
		$image_movil = wp_get_attachment_url($url_img_movil);

		if ($image_desk || $image_movil) {
			echo '<div class="prodcat_banner"><img class="banner_desk" src="' . $image_desk . '" /><img class="banner_movil" src="' . $image_movil . '" /></div>';
		}
	}
}

/*Quitar la sidebar de storefront en paginas especificas*/
add_action('get_header', 'remove_storefront_sidebar');
function remove_storefront_sidebar()
{
	if (!is_woocommerce() || is_product()) {
		remove_action('storefront_sidebar', 'storefront_get_sidebar', 10);
	}
}
//Incluimos el archivo de funciones de Alegra
require_once('alegra.php');
require_once('import_product.php');