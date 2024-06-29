<?php
/* INTEGRACION ALEGRA */
add_action('add_meta_boxes', 'add_custom_fields_metabox');
function add_custom_fields_metabox()
{
	add_meta_box(
		'custom_fields_metabox', // ID único
		'Respuesta de ALEGRA', // Título del metabox
		'display_custom_fields_metabox', // Función de callback para mostrar el contenido
		'product', // Pantalla (post type)
		'normal', // Contexto (donde se muestra en la pantalla)
		'default' // Prioridad
	);
}
function display_custom_fields_metabox($post)
{
	// Usa wp_nonce_field para crear un campo de seguridad en el formulario
	wp_nonce_field(basename(__FILE__), 'custom_fields_nonce');
	// Obtener los campos personalizados actuales
	$meta_fields = get_post_meta($post->ID);
	echo '<table class="form-table">';
	// Mostrar cada campo personalizado
	foreach ($meta_fields as $key => $value) {
		// Ignorar campos internos de WordPress
		if (is_protected_meta($key, 'post')) {
			continue;
		}
		//Buscamos que el campo tenga en el texto alegra
		if (strpos($key, 'alegra') === false) {
			continue;
		}
		// Mostrar el campo
		echo '<tr>';
		echo '<th><label for="' . $key . '">' . esc_html($key) . '</label></th>';
		echo '<td>';
		echo '<textarea id="' . $key . '" name="' . $key . '" rows="5" style="width: 100%;">' . esc_textarea($value[0]) . '</textarea>';
		echo '</td>';
		echo '</tr>';
	}
	echo '</table>';
}
// Enviamos por curl
function sendCurl($url, $data, $method = 'POST')
{
	$alegra_user = 'mariach161@hotmail.com';
	$alegra_token = 'f692f6484a39023c7ead';
	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_POSTFIELDS => json_encode($data),
		CURLOPT_HTTPHEADER => [
			"accept: application/json",
			"authorization: Basic " . base64_encode($alegra_user . ":" . $alegra_token),
			"content-type: application/json"
		],
	]);
	$response = curl_exec($curl);
	$err = curl_error($curl);
	curl_close($curl);
	if ($err) {
		return null;
	} else {
		return json_decode($response, true);
	}
}

// Hook para guardar o actualizar productos
add_action('woocommerce_after_product_object_save', 'sync_product_to_alegra', 10, 2);

function sync_product_to_alegra($product, $data_store)
{
    $post_id = $product->get_id();
	// Obtener el metacampo id_alegra
	$id_alegra = get_post_meta($post_id, '_alegra_product_id', true);
	if (!$id_alegra) {
		// Si no existe, crear el producto en Alegra
		$id_alegra = create_product_in_alegra($product);
		// Guardar el id_alegra en el metacampo
		update_post_meta($post_id, '_alegra_product_id', $id_alegra);
	} else {
		// Si existe, actualizar el producto en Alegra
		update_product_in_alegra($product, $id_alegra);
	}
}

// Verificar si el atributo de variante existe en Alegra
function get_variant_attribute_from_alegra($attribute_name) {
	$url = 'https://api.alegra.com/api/v1/variant-attributes/';
	$response = sendCurl($url, [], 'GET');
	if ($response) {
		foreach ($response as $attribute) {
			if (strcasecmp($attribute['name'], $attribute_name) == 0) {
				return $attribute;
			}
		}
	}
	return null;
}

// Crear o actualizar atributos de variación en Alegra
function create_variant_attribute_in_alegra($attribute_name, $options) {
	$existing_attribute = get_variant_attribute_from_alegra($attribute_name);
	if ($existing_attribute) {
		// Actualizar el atributo existente con nuevas opciones si es necesario
		$existing_options = array_column($existing_attribute['options'], 'value');
		$new_options = array_diff($options, $existing_options);
		if (!empty($new_options)) {
			$url = 'https://api.alegra.com/api/v1/variant-attributes/' . $existing_attribute['id'];
			
			$existing_attribute['options'] = array_merge($existing_attribute['options'], array_map(function($option) {
				return ['value' => $option];
			}, $new_options));
			$response = sendCurl($url, $existing_attribute, 'PUT');
			return $response;
		}
		return $existing_attribute;
	} else {
		// Crear un nuevo atributo si no existe
		$url = 'https://api.alegra.com/api/v1/variant-attributes';
		$attributeData = [
			'name' => $attribute_name,
			'options' => array_map(function($option) {
				return ['value' => $option];
			}, $options),
			'status' => 'active'
		];
		$response = sendCurl($url, $attributeData, 'POST');
		return $response;
	}
}

// Crear producto en Alegra
function create_product_in_alegra($product) {
	$url = 'https://api.alegra.com/api/v1/items/';
	$id_product = $product->get_id();

	// Verificamos si está activo el seguimiento de inventario
	$stock_quantity = get_post_meta($id_product, '_stock', true);
	$manage_stock = get_post_meta($id_product, '_manage_stock', true);
	if ($manage_stock == 'yes') {
		$stock_quantity = $stock_quantity ? $stock_quantity : 0;
	} else {
		$stock_quantity = 0;
	}

	$price = $product->get_price();
	//Si es un prducto variable obtenemos el precio de la variacion 
	if ($product->is_type('variable')) {
		$available_variations = $product->get_available_variations();
		if (!empty($available_variations)) {
			$price = $available_variations[0]['display_price'];
		}
	}
	$price_2 = get_post_meta($id_product, 'variable_price_2', true);
	$price_3 = get_post_meta($id_product, 'variable_price_3', true);
	// Datos básicos del producto
	$data = [
		'name' => $product->get_name(),
		'price' => [
			0 => [
				'idPriceList' => 1,
				'price' => $price,
			],
			1 => [
				'idPriceList' => 2,
				'price' => $price_2,
			],
			2 => [
				'idPriceList' => 3,
				'price' => $price_3,
			]

		],
		'reference' => $product->get_sku(),
		'description' => $product->get_description(),
		'inventory' => [
			'unit' => 'unit',
			'unitCost' => $price,
			'negativeSale' => true,
			'warehouses' => [
				[
					'id' => 1,
					'initialQuantity' => $stock_quantity,
					'minQuantity' => 1,
					'maxQuantity' => 1000
				]
			]
		]
	];
	update_post_meta($id_product, 'variables_send_alegra', json_encode($data));
	/*
	// Verificamos si el producto es variable
	if ($product->is_type('variable')) {
		//$data['type'] = 'variantParent';
		$available_variations = $product->get_available_variations();
		if (!empty($available_variations)) {
			$variant_attributes = [];
			foreach ($available_variations as $variation) {
				foreach ($variation['attributes'] as $attribute_name => $option) {
					$attribute_name = wc_attribute_label($attribute_name); // Obtener el nombre legible del atributo
					$variant_attributes[$attribute_name][] = $option;
				}
			}
			foreach ($variant_attributes as $attribute_name => $options) {
				$attribute_response = create_variant_attribute_in_alegra($attribute_name, array_unique($options));
				/*
				if (isset($attribute_response['id'])) {
					$data['variantAttributes'][] = [
						'id' => (int) $attribute_response['id'],
						'options' => $attribute_response['options']
					];
				}
				
			}
		}
	}
	*/
	// Enviar datos a Alegra
	$response = sendCurl($url, $data, 'POST');
	update_post_meta($id_product, 'response_alegra', json_encode($response));

	if ($response) {
		return $response['id'];
	}
	return $response;
}

// Actualizar producto en Alegra
function update_product_in_alegra($product, $id_alegra) {
	$url = 'https://api.alegra.com/api/v1/items/' . $id_alegra;
	$id_product = $product->get_id();

	// Verificamos si está activo el seguimiento de inventario
	$stock_quantity = get_post_meta($id_product, '_stock', true);
	$manage_stock = get_post_meta($id_product, '_manage_stock', true);
	if ($manage_stock == 'yes') {
		$stock_quantity = $stock_quantity ? $stock_quantity : 0;
	} else {
		$stock_quantity = 0;
	}
	$price = $product->get_price();
	$price_2 = get_post_meta($id_product, 'variable_price_2', true);
	$price_3 = get_post_meta($id_product, 'variable_price_3', true);
	// Datos básicos del producto
	$data = [
		'name' => $product->get_name(),
		'price' => [
			0 => [
				'idPriceList' => 1,
				'price' => $price,
			],
			1 => [
				'idPriceList' => 2,
				'price' => $price_2,
			],
			2 => [
				'idPriceList' => 3,
				'price' => $price_3,
			]

		],
		'reference' => $product->get_sku(),
		'description' => $product->get_description(),
		'inventory' => [
			'unit' => 'unit',
			'unitCost' => $product->get_price(),
			'negativeSale' => true,
			'warehouses' => [
				[
					'id' => 1,
					'initialQuantity' => $stock_quantity,
					'minQuantity' => 1,
					'maxQuantity' => 1000
				]
			]
		],
	];

	// Verificamos si el producto es variable
	if ($product->is_type('variable')) {
		$available_variations = $product->get_available_variations();
		if (!empty($available_variations)) {
			$variant_attributes = [];
			foreach ($available_variations as $variation) {
				foreach ($variation['attributes'] as $attribute_name => $option) {
					$attribute_name = wc_attribute_label($attribute_name); // Obtener el nombre legible del atributo
					$variant_attributes[$attribute_name][] = $option;
				}
			}
			foreach ($variant_attributes as $attribute_name => $options) {
				$attribute_response = create_variant_attribute_in_alegra($attribute_name, array_unique($options));
				if (isset($attribute_response['id'])) {
					$data['variantAttributes'][] = [
						'id' => $attribute_response['id'],
						'options' => $attribute_response['options']
					];
				}
			}
		}
	}

	// Enviar datos a Alegra
	$response = sendCurl($url, $data, 'PUT');
	update_post_meta($id_product, 'response_alegra', json_encode($response));

	if ($response) {
		return $response['id'];
	}
	return $response;
}