CREATE OR REPLACE VIEW commande_sync_salesforce AS

WITH postmeta_agg AS (
    SELECT 
        pm.post_id,
        MAX(CASE WHEN pm.meta_key = '_paid_date' THEN pm.meta_value END) AS _paid_date,
        MAX(CASE WHEN pm.meta_key = '_payment_method_title' THEN pm.meta_value END) AS _payment_method_title,
        MAX(CASE WHEN pm.meta_key = '_order_currency' THEN pm.meta_value END) AS _order_currency,
        MAX(CASE WHEN pm.meta_key = '_organisation_unique_id' THEN pm.meta_value END) AS _organisation_unique_id,
        MAX(CASE WHEN pm.meta_key = '_customer_user' THEN pm.meta_value END) AS _customer_user,
        MAX(CASE WHEN pm.meta_key = '_order_total' THEN pm.meta_value END) AS _order_total,
        MAX(CASE WHEN pm.meta_key = '_wc_order_attribution_utm_source' THEN pm.meta_value END) AS _wc_order_attribution_utm_source
    FROM wp_postmeta pm 
    WHERE pm.meta_key IN (
        '_paid_date',
        '_payment_method_title',
        '_order_currency', 
        '_organisation_unique_id',
        '_customer_user',
        '_order_total', 
        '_wc_order_attribution_utm_source'
    )
    GROUP BY pm.post_id
),

itemmeta_agg AS (
    SELECT 
        oim.order_item_id,
        MAX(CASE WHEN oim.meta_key = '_product_id' THEN oim.meta_value END) AS _product_id,
        MAX(CASE WHEN oim.meta_key = '_line_total' THEN oim.meta_value END) AS _line_total,
        MAX(CASE WHEN oim.meta_key = '_qty' THEN oim.meta_value END) AS _qty
    FROM wp_woocommerce_order_itemmeta oim 
    WHERE oim.meta_key IN ('_product_id', '_line_total', '_qty')
    GROUP BY oim.order_item_id
)

SELECT 
    oi.order_item_id AS id_ligne,
    p.ID AS order_id,
    p.post_date AS order_date,
    p.post_status AS order_status,
    pm._paid_date AS payment_date,
    pm._payment_method_title AS payment_method,
    pm._order_currency AS currency,
    COALESCE(NULLIF(TRIM(pm._organisation_unique_id), ''), 'ORG-00000000') AS organisation_id,
    CAST(NULLIF(pm._customer_user, '') AS UNSIGNED) AS user_id,
    oi.order_item_name AS product_name,
    CAST(pm._order_total AS DECIMAL(18,6)) AS order_total,
    CAST(NULLIF(ima._line_total, '') AS DECIMAL(18,6)) AS product_price,
    CAST(NULLIF(ima._qty, '') AS DECIMAL(18,6)) AS quantity,
    pm._wc_order_attribution_utm_source AS purchase_origin,
    CAST(NULLIF(ima._product_id, '') AS UNSIGNED) AS product_id

FROM wp_posts p
JOIN wp_woocommerce_order_items oi ON p.ID = oi.order_id
LEFT JOIN postmeta_agg pm ON pm.post_id = p.ID
LEFT JOIN itemmeta_agg ima ON ima.order_item_id = oi.order_item_id

WHERE p.post_type = 'shop_order'
  AND oi.order_item_type = 'line_item'
  AND NULLIF(TRIM(ima._product_id), '') IS NOT NULL;
