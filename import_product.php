<?php
/* IMPORTAR PRODUCTOS */// Asegúrate de que Composer está cargado
// Asegúrate de que Composer está cargado// Asegúrate de que Composer está cargado
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

function custom_log($message) {
    $theme_dir = get_stylesheet_directory();
    $log_file = $theme_dir . '/import_log.txt';
    $current_time = current_time('Y-m-d H:i:s');
    $log_message = $current_time . " - " . $message . "\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

function custom_import_products_from_excel() {
    // Verifica si se ha subido un archivo
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == 0) {
        $file = $_FILES['excel_file']['tmp_name'];

        // Procesa el archivo directamente para depuración
        process_excel_file($file);

        // Redirige con un mensaje de éxito
        wp_redirect(add_query_arg('import_status', 'success', wp_get_referer()));
        exit;
    } else {
        // Redirige con un mensaje de error
        wp_redirect(add_query_arg('import_status', 'error', wp_get_referer()));
        exit;
    }
}
add_action('admin_post_import_products', 'custom_import_products_from_excel');

function process_excel_file($file) {
    echo "Procesando archivo: " . $file . "\n";
    
    // Vaciamos el log
    $theme_dir = get_stylesheet_directory();
    $log_file = $theme_dir . '/import_log.txt';
    file_put_contents($log_file, '');
    custom_log("Inicio de procesamiento del archivo: " . $file);

    try {
        // Carga el archivo Excel
        $spreadsheet = IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        // Salta la primera fila si es el encabezado
        $header = array_shift($rows);
        foreach ($rows as $index => $row) {
            custom_log("Procesando fila " . ($index + 2));

            // Asignación de variables
            $product_type = $row[0];
            $product_name = $row[1];
            $price = $row[2];
            $price_1 = $row[3];
            $price_2 = $row[4];
            $sku = $row[5];
            $stock = $row[6];
            $category = $row[7];
            $image_url = $row[8];
            $description = $row[9];
            $alegra_product_id = $row[10];
            $attribute_name = isset($row[11]) ? $row[11] : '';
            $attribute_values = isset($row[12]) ? $row[12] : '';
            $variation_prices = isset($row[13]) ? explode(',', $row[13]) : [];
            $variation_prices_1 = isset($row[14]) ? explode(',', $row[14]) : [];
            $variation_prices_2 = isset($row[15]) ? explode(',', $row[15]) : [];
            $variation_skus = isset($row[16]) ? explode(',', $row[16]) : [];
            $variation_stocks = isset($row[17]) ? explode(',', $row[17]) : [];
            $variation_image_urls = isset($row[18]) ? explode(',', $row[18]) : [];

            // Si no trae nombre de producto, se salta la fila
            if (empty($product_name)) {
                continue;
            }

            // Crea o actualiza el producto
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                // Crear nuevo producto
                if ($product_type === 'variable') {
                    $product = new WC_Product_Variable();
                } else {
                    $product = new WC_Product_Simple();
                }
                $product->set_name($product_name);
                $product->set_description($description);
                $product->set_sku($sku);

                if($product_type === 'simple'){
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($stock);
                }

                $product->set_regular_price($price);
                $product->set_category_ids([$category]);
                
                // Asigna la imagen del producto
                if ($image_url) {
                    $image_id = media_sideload_image($image_url, 0, '', 'id');
                    if (!is_wp_error($image_id)) {
                        $product->set_image_id($image_id);
                    }
                }

                // Guardar producto
                $product_id = $product->save();
                custom_log("Producto creado: " . $product_name);
            } else {
                // Actualizar producto existente
                $product = wc_get_product($product_id);
                $product->set_description($description);
                if($product_type === 'simple'){
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($stock);
                }
                $product->set_regular_price($price);
                $product->set_category_ids([$category]);

                // Asigna la imagen del producto
                if ($image_url) {
                    $image_id = media_sideload_image($image_url, 0, '', 'id');
                    if (!is_wp_error($image_id)) {
                        $product->set_image_id($image_id);
                    }
                }
                $product->save();
                custom_log("Producto actualizado: " . $product_name);
            }

            if($alegra_product_id != ''){
                //Guardamos el  $alegra_product_id en el producto como metacampo
                update_post_meta($product_id, 'alegra_product_id', $alegra_product_id);
            }

            update_post_meta($product_id, 'variable_price_2', $price_1);
            update_post_meta($product_id, 'variable_price_3', $price_2);

            // Manejo de productos variables
            if ($product_type === 'variable') {
                // Asignar atributos
                $attribute_taxonomy = wc_attribute_taxonomy_name($attribute_name);
                if (!taxonomy_exists($attribute_taxonomy)) {
                    // Crear el atributo si no existe
                    $attribute_id = wc_create_attribute([
                        'name' => $attribute_name,
                        'slug' => sanitize_title($attribute_name),
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    ]);
                    register_taxonomy(
                        $attribute_taxonomy,
                        apply_filters('woocommerce_taxonomy_objects_' . $attribute_taxonomy, ['product']),
                        apply_filters('woocommerce_taxonomy_args_' . $attribute_taxonomy, [
                            'labels' => [
                                'name' => $attribute_name,
                            ],
                            'hierarchical' => true,
                            'show_ui' => false,
                            'query_var' => true,
                            'rewrite' => false,
                        ])
                    );
                }

                // Crear los términos del atributo si no existen
                $values = explode(',', $attribute_values);
                foreach ($values as $value) {
                    if (!term_exists($value, $attribute_taxonomy)) {
                        wp_insert_term($value, $attribute_taxonomy);
                    }
                }

                // Asignar el atributo al producto
                wp_set_object_terms($product_id, $values, $attribute_taxonomy);

                // Actualizar los atributos del producto
                $product_attributes = [
                    $attribute_taxonomy => [
                        'name' => $attribute_taxonomy,
                        'value' => '',
                        'position' => 0,
                        'is_visible' => 1,
                        'is_variation' => 1,
                        'is_taxonomy' => 1,
                    ],
                ];
                update_post_meta($product_id, '_product_attributes', $product_attributes);

                // Crear o actualizar variaciones
                foreach ($values as $index => $value) {
                    // Busca si la variación ya existe
                    $args = [
                        'post_type' => 'product_variation',
                        'post_status' => ['private', 'publish'],
                        'numberposts' => 1,
                        'post_parent' => $product_id,
                        'meta_key' => 'attribute_' . $attribute_taxonomy,
                        'meta_value' => $value,
                    ];
                    $existing_variation = get_posts($args);

                    if ($existing_variation) {
                        $variation_id = $existing_variation[0]->ID;
                        $variation = new WC_Product_Variation($variation_id);
                        custom_log("Variación actualizada: " . $product_name . ' - ' . $value);
                    } else {
                        // Crear nueva variación si no existe
                        $variation = new WC_Product_Variation();
                        $variation->set_parent_id($product_id);
                        //Obtenemos el slug del termino para asignarlo a la variación
                        $term = get_term_by('name', $value, $attribute_taxonomy);
                        $value = $term->slug;
                        $variation->set_attributes([$attribute_taxonomy => $term->slug]);
                        custom_log("Variación creada: " . $product_name . ' - ' . $term->slug);
                    }

                    $variation->set_regular_price(isset($variation_prices[$index]) ? $variation_prices[$index] : $price);
                    $variation->set_sku(isset($variation_skus[$index]) ? $variation_skus[$index] : $sku . '-' . $value);
                    $variation->set_stock_quantity(isset($variation_stocks[$index]) ? $variation_stocks[$index] : $stock);
                    $variation->set_manage_stock(true);
                    $variation->update_meta_data('variable_price_2', isset($variation_prices_1[$index]) ? $variation_prices_1[$index] : $price_1);
                    $variation->update_meta_data('variable_price_3', isset($variation_prices_2[$index]) ? $variation_prices_2[$index] : $price_2);
                    // Asigna la imagen de la variación
                    if (isset($variation_image_urls[$index])) {
                        $variation_image_id = media_sideload_image($variation_image_urls[$index], 0, '', 'id');
                        if (!is_wp_error($variation_image_id)) {
                            $variation->set_image_id($variation_image_id);
                        }
                    }

                    $variation->save();
                }

                // Guardar el producto para asegurar que las variaciones se apliquen
                $product->save();
            }
        }

        custom_log("Procesamiento del archivo finalizado.");
    } catch (Exception $e) {

        custom_log("Error procesando archivo: " . $e->getMessage());
    }
}



// Añadir el formulario en el administrador de WordPress
function custom_admin_menu() {
    add_menu_page('Importar Productos', 'Importar Productos', 'manage_options', 'import-products', 'custom_import_products_page');
}
add_action('admin_menu', 'custom_admin_menu');

function custom_import_products_page() {
    ?>
    <div class="wrap">
        <h1>Importar Productos desde Excel</h1>
        <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="import_products">
            <input type="file" name="excel_file" accept=".xlsx, .xls">
            <?php submit_button('Importar'); ?>
        </form>
        <?php if (isset($_GET['import_status'])): ?>
            <?php if ($_GET['import_status'] == 'success'): ?>
                <div class="updated notice"><p>El archivo se ha procesado con éxito.</p></div>
            <?php elseif ($_GET['import_status'] == 'error'): ?>
                <div class="error notice"><p>Hubo un error al subir el archivo. Por favor, inténtalo de nuevo.</p></div>
            <?php endif; ?>
        <?php endif; ?>
        <h2>Log de Importación</h2>
        <pre>
            <?php
            $theme_dir = get_stylesheet_directory();
            $log_file = $theme_dir . '/import_log.txt';
            if (file_exists($log_file)) {
                echo file_get_contents($log_file);
            } else {
                echo "No hay logs disponibles.";
            }
            ?>
        </pre>
    </div>
    <?php
}
