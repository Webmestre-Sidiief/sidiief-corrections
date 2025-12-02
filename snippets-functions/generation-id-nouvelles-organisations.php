
<?php
/**
 * ===========================================================
 * SCRIPT FIXE — Génération automatique d'ID pour les organisations
 * -----------------------------------------------------------
 * - Crée un ID unique (ORG-XXXXXXXX) pour tout utilisateur
 *   qui remplit le champ personnalisé billing_wooccm12
 * - Fonctionne pour :
 *   • Nouveaux utilisateurs (enregistrement)
 *   • Mise à jour du profil
 *   • Checkout WooCommerce
 * ===========================================================
 */

function swp_assegna_id_unico_usuario($user_id) {
    if (!$user_id) return;

    // Vérifier le champ billing_wooccm12
    $org_name = get_user_meta($user_id, 'billing_wooccm12', true);
    if (empty($org_name)) return;

    // Vérifier si un ID existe déjà
    $existing_id = get_user_meta($user_id, '_organisation_unique_id', true);
    if (!empty($existing_id)) return;

    // Générer un ID unique
    $unique_id = 'ORG-' . strtoupper(wp_generate_password(8, false, false));

    // Sauvegarder
    update_user_meta($user_id, '_organisation_unique_id', $unique_id);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("🆔 ID unique créé: {$unique_id} pour {$org_name} (user {$user_id})");
    }
}

// Hooks
add_action('user_register', 'swp_assegna_id_unico_usuario', 10, 1);
add_action('profile_update', 'swp_assegna_id_unico_usuario', 10, 1);
add_action('woocommerce_checkout_update_user_meta', 'swp_assegna_id_unico_usuario', 10, 1);
