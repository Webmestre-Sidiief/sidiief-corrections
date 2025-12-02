<?php
/**
 * ===========================================================
 * MIGRAÇÃO ÚNICA — Organizações Antigas + Endereços + Propagação
 * -----------------------------------------------------------
 * - Preenche billing_wooccm12 com team_name do owner se vazio
 * - Gera _organisation_unique_id se ausente (ORG-XXXXXXXX)
 * - Copia endereços do pedido para o owner (somente se vazio)
 * - Propaga _organisation_unique_id para membros do mesmo time
 * - Dry run disponível: ?run_org_migration=1&dry_run=1
 * ===========================================================
 */

add_action('init', function() {

    if (!isset($_GET['run_org_migration'])) return;

    if (!current_user_can('manage_options')) {
        wp_die("Acesso negado. Apenas administradores podem executar esta migração.");
    }

    global $wpdb;
    $dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] == 1;

    echo "<pre>";
    echo "=== MIGRAÇÃO DE ORGANIZAÇÕES ANTIGAS ===\n";
    echo $dry_run ? "*** DRY RUN — Nenhuma modificação será feita ***\n" : "*** EXECUÇÃO REAL ***\n";

    // Mapeamento de chaves do pedido → user meta
    $address_keys = [
        '_billing_first_name' => 'billing_first_name',
        '_billing_last_name'  => 'billing_last_name',
        '_billing_company'    => 'billing_company',
        '_billing_address_1'  => 'billing_address_1',
        '_billing_address_2'  => 'billing_address_2',
        '_billing_city'       => 'billing_city',
        '_billing_state'      => 'billing_state',
        '_billing_postcode'   => 'billing_postcode',
        '_billing_country'    => 'billing_country',
        '_billing_phone'      => 'billing_phone',
        '_billing_email'      => 'billing_email'
    ];

    // 1. Buscar todos os times e seus owners, considerando apenas o mais recente
    $teams = $wpdb->get_results("
        SELECT t.ID AS team_id, t.post_title AS team_name, t.post_author AS owner_id, tm.meta_value AS order_id
        FROM {$wpdb->posts} t
        LEFT JOIN {$wpdb->postmeta} tm ON tm.post_id = t.ID AND tm.meta_key = '_order_id'
        WHERE t.post_type = 'wc_memberships_team'
          AND t.post_status = 'publish'
        ORDER BY t.post_date DESC
    ");

    if (empty($teams)) {
        echo "Nenhum time encontrado.\n";
        echo "</pre>";
        exit;
    }

    // Indexar times por owner (somente o mais recente)
    $owners_index = [];
    foreach ($teams as $row) {
        $owner_id = intval($row->owner_id);
        if (!isset($owners_index[$owner_id])) {
            $owners_index[$owner_id] = [
                'team_id'   => $row->team_id,
                'team_name' => $row->team_name,
                'order_id'  => $row->order_id
            ];
        }
    }

    $report = ['owners_updated' => [], 'members_updated' => []];

    foreach ($owners_index as $owner_id => $team_info) {
        $team_id   = intval($team_info['team_id']);
        $team_name = trim($team_info['team_name']);
        $order_id  = intval($team_info['order_id']);

        // --- 1. Preencher billing_wooccm12 ---
        $existing_name = trim(get_user_meta($owner_id, 'billing_wooccm12', true));
        if (empty($existing_name) && !empty($team_name)) {
            if (!$dry_run) update_user_meta($owner_id, 'billing_wooccm12', $team_name);
            $report['owners_updated'][] = [
                'owner_id' => $owner_id,
                'field'    => 'billing_wooccm12',
                'value'    => $team_name
            ];
        }

        // --- 2. Gerar _organisation_unique_id ---
        $existing_org_id = trim(get_user_meta($owner_id, '_organisation_unique_id', true));
        if (empty($existing_org_id)) {
            $existing_org_id = 'ORG-' . strtoupper(wp_generate_password(8, false, false));
            if (!$dry_run) update_user_meta($owner_id, '_organisation_unique_id', $existing_org_id);
            $report['owners_updated'][] = [
                'owner_id' => $owner_id,
                'field'    => '_organisation_unique_id',
                'value'    => $existing_org_id
            ];
        }

        // --- 3. Copiar endereços do pedido para o owner ---
        if ($order_id > 0) {
            $order_meta_values = $wpdb->get_results($wpdb->prepare("
                SELECT meta_key, meta_value
                FROM {$wpdb->postmeta}
                WHERE post_id = %d
                  AND meta_key IN ('_billing_first_name','_billing_last_name','_billing_company',
                                   '_billing_address_1','_billing_address_2','_billing_city',
                                   '_billing_state','_billing_postcode','_billing_country',
                                   '_billing_phone','_billing_email')
            ", $order_id), OBJECT_K);

            foreach ($address_keys as $order_key => $user_key) {
                $order_value = isset($order_meta_values[$order_key]) ? trim($order_meta_values[$order_key]->meta_value) : '';
                $existing_user_value = trim(get_user_meta($owner_id, $user_key, true));
                if (!empty($order_value) && empty($existing_user_value)) {
                    if (!$dry_run) update_user_meta($owner_id, $user_key, $order_value);
                    $report['owners_updated'][] = [
                        'owner_id' => $owner_id,
                        'field'    => $user_key,
                        'value'    => $order_value
                    ];
                }
            }
        }

        // --- 4. Propagar _organisation_unique_id para membros ---
        $member_ids = $wpdb->get_col($wpdb->prepare("
            SELECT meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_user_id'
              AND post_id IN (
                  SELECT post_id
                  FROM {$wpdb->postmeta}
                  WHERE meta_key = '_team_id'
                    AND meta_value = %d
              )
        ", $team_id));

        foreach ($member_ids as $member_id) {
            $member_id = intval($member_id);
            if ($member_id === $owner_id) continue;
            $existing_member_org = trim(get_user_meta($member_id, '_organisation_unique_id', true));
            if (empty($existing_member_org)) {
                if (!$dry_run) update_user_meta($member_id, '_organisation_unique_id', $existing_org_id);
                $report['members_updated'][] = [
                    'member_id' => $member_id,
                    'org_id'    => $existing_org_id
                ];
            }
        }
    }

    // --- 5. Relatório final ---
    echo "\n### RELATÓRIO DA MIGRAÇÃO ###\n";
    echo "\nOwners atualizados:\n";
    print_r($report['owners_updated']);
    echo "\nMembros atualizados:\n";
    print_r($report['members_updated']);
    echo $dry_run ? "\n*** DRY RUN — Nenhuma modificação feita ***\n" : "\n*** EXECUÇÃO REAL CONCLUÍDA ***\n";
    echo "</pre>";

    exit;
});
?>
