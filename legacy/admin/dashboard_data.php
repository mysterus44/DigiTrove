<?php
// admin/dashboard_data.php
require_once __DIR__ . '/../includes/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';

// Sécurité
if (!is_admin()) {
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}

$page = $_GET['page'] ?? 'overview';
$response = ['html' => '', 'script' => ''];

// --- RÉCUPÉRATION DES DONNÉES ---
$produits = get_json_data('produits.json');
$users_count = 0;

try {
    $pdo = get_db_connection();
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $users_count = $stmt->fetchColumn();
} catch (Exception $e) {
    $users_count = "N/A";
}

// Calculs Stats
$total_ventes = 0;
$chiffre_affaires = 0;
foreach ($produits as $p) {
    $v = intval($p['ventes'] ?? 0);
    $prix = intval(str_replace(' ', '', $p['prix_reduit'] ?? 0)); // Nettoyage du prix
    $total_ventes += $v;
    $chiffre_affaires += ($v * $prix);
}

// --- GÉNÉRATION DU CONTENU HTML ---
ob_start();

if ($page === 'overview') {
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-gray-400">Chiffre d'Affaires</p>
                    <h3 class="text-2xl font-bold text-white mt-1"><?php echo number_format($chiffre_affaires, 0, ',', ' '); ?> F</h3>
                </div>
                <div class="p-2 bg-green-500/20 rounded-lg text-green-500"><i data-lucide="dollar-sign"></i></div>
            </div>
        </div>
        <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-gray-400">Ventes Totales</p>
                    <h3 class="text-2xl font-bold text-white mt-1"><?php echo $total_ventes; ?></h3>
                </div>
                <div class="p-2 bg-blue-500/20 rounded-lg text-blue-500"><i data-lucide="shopping-cart"></i></div>
            </div>
        </div>
        <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-gray-400">Utilisateurs</p>
                    <h3 class="text-2xl font-bold text-white mt-1"><?php echo $users_count; ?></h3>
                </div>
                <div class="p-2 bg-purple-500/20 rounded-lg text-purple-500"><i data-lucide="users"></i></div>
            </div>
        </div>
        <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-gray-400">Produits Actifs</p>
                    <h3 class="text-2xl font-bold text-white mt-1"><?php echo count($produits); ?></h3>
                </div>
                <div class="p-2 bg-orange-500/20 rounded-lg text-orange-500"><i data-lucide="package"></i></div>
            </div>
        </div>
    </div>

    <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg mb-8">
        <h3 class="text-lg font-semibold text-white mb-4">Aperçu des Ventes</h3>
        <div class="h-64 w-full">
            <canvas id="salesChart"></canvas>
        </div>
    </div>

    <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-lg overflow-hidden">
        <div class="p-6 border-b border-gray-700">
            <h3 class="text-lg font-semibold text-white">Top Produits</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-gray-700/50 text-gray-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3">Produit</th>
                        <th class="px-6 py-3">Prix</th>
                        <th class="px-6 py-3">Ventes</th>
                        <th class="px-6 py-3">Revenus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700 text-sm text-gray-300">
                    <?php 
                    // Trier par ventes pour le top
                    usort($produits, fn($a, $b) => $b['ventes'] - $a['ventes']);
                    $top_5 = array_slice($produits, 0, 5);
                    
                    foreach($top_5 as $p): 
                        $rev = intval($p['ventes']) * intval(str_replace(' ', '', $p['prix_reduit']));
                    ?>
                    <tr class="hover:bg-gray-700/30 transition">
                        <td class="px-6 py-4 font-medium text-white flex items-center gap-3">
                            <img src="<?php echo asset(str_replace('../', '', $p['image'])); ?>" class="w-8 h-8 rounded object-cover bg-gray-600">
                            <?php echo e($p['nom']); ?>
                        </td>
                        <td class="px-6 py-4"><?php echo e($p['prix_reduit']); ?> F</td>
                        <td class="px-6 py-4"><?php echo $p['ventes']; ?></td>
                        <td class="px-6 py-4 text-green-400"><?php echo number_format($rev, 0, ',', ' '); ?> F</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    // Script pour le graphique (Chart.js)
    $response['script'] = "
        var ctx = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'],
                datasets: [{
                    label: 'Ventes',
                    data: [12, 19, 3, 5, 2, 3, 15],
                    borderColor: '#F76507',
                    backgroundColor: 'rgba(247, 101, 7, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { grid: { color: '#374151' }, ticks: { color: '#9CA3AF' } },
                    x: { grid: { display: false }, ticks: { color: '#9CA3AF' } }
                }
            }
        });
    ";

} elseif ($page === 'products') {
    ?>
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Gestion des Produits</h2>
        <a href="gestion-produits.php" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg transition text-sm flex items-center">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i> Ajouter
        </a>
    </div>
    <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
        <table class="w-full text-left">
            <thead class="bg-gray-700/50 text-gray-400 uppercase text-xs">
                <tr>
                    <th class="px-6 py-3">Image</th>
                    <th class="px-6 py-3">Nom</th>
                    <th class="px-6 py-3">Prix</th>
                    <th class="px-6 py-3">Type</th>
                    <th class="px-6 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700 text-sm text-gray-300">
                <?php foreach($produits as $p): ?>
                <tr class="hover:bg-gray-700/30">
                    <td class="px-6 py-4">
                        <img src="<?php echo asset(str_replace('../', '', $p['image'])); ?>" class="w-10 h-10 rounded object-cover bg-gray-600">
                    </td>
                    <td class="px-6 py-4 font-medium text-white"><?php echo e($p['nom']); ?></td>
                    <td class="px-6 py-4"><?php echo e($p['prix_reduit']); ?> F</td>
                    <td class="px-6 py-4"><span class="px-2 py-1 bg-gray-700 rounded text-xs"><?php echo e($p['type']); ?></span></td>
                    <td class="px-6 py-4 flex gap-2">
                        <a href="edit_produit.php?id=<?php echo $p['id']; ?>" class="text-blue-400 hover:text-blue-300"><i data-lucide="edit-2" class="w-4 h-4"></i></a>
                        <a href="delete_produit.php?id=<?php echo $p['id']; ?>" onclick="return confirm('Supprimer ?')" class="text-red-400 hover:text-red-300"><i data-lucide="trash" class="w-4 h-4"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php

} elseif ($page === 'users') {
    try {
        $pdo = get_db_connection();
        // Récupérer tous les utilisateurs
        $stmt = $pdo->query("SELECT * FROM users ORDER BY date_inscription DESC");
        $users = $stmt->fetchAll();
    } catch (Exception $e) {
        $users = [];
    }
    ?>
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Gestion des Utilisateurs</h2>
        <a href="gestion-utilisateurs.php" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg transition text-sm flex items-center">
            <i data-lucide="user-plus" class="w-4 h-4 mr-2"></i> Ajouter
        </a>
    </div>

    <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow-lg">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-gray-700/50 text-gray-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3">Utilisateur</th>
                        <th class="px-6 py-3">Email</th>
                        <th class="px-6 py-3">Rôle</th>
                        <th class="px-6 py-3">Mot de passe</th>
                        <th class="px-6 py-3">Date Inscription</th>
                        <th class="px-6 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700 text-sm text-gray-300">
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">Aucun utilisateur trouvé.</td></tr>
                    <?php else: ?>
                        <?php foreach($users as $u): 
                            $initial = strtoupper(substr($u['prenom'], 0, 1) . substr($u['nom'], 0, 1));
                        ?>
                        <tr class="hover:bg-gray-700/30 transition">
                            <td class="px-6 py-4 flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-gray-600 flex items-center justify-center text-xs font-bold text-white">
                                    <?php echo $initial; ?>
                                </div>
                                <div>
                                    <span class="block font-medium text-white"><?php echo e($u['prenom'] . ' ' . $u['nom']); ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-400"><?php echo e($u['email']); ?></td>
                            <td class="px-6 py-4">
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="px-2 py-1 bg-red-900/30 text-red-400 rounded text-xs border border-red-900/50">Admin</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-blue-900/30 text-blue-400 rounded text-xs border border-blue-900/50">Client</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-xs text-gray-500 flex items-center gap-1">
                                    <i data-lucide="lock" class="w-3 h-3"></i> Sécurisé (Haché)
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-400 text-xs">
                                <?php echo date("d/m/Y H:i", strtotime($u['date_inscription'])); ?>
                            </td>
                            <td class="px-6 py-4 flex items-center gap-3">
                                <a href="gestion-utilisateurs.php?action=edit&id=<?php echo $u['id']; ?>" class="text-blue-400 hover:text-blue-300 transition" title="Modifier">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </a>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <a href="gestion-utilisateurs.php?action=delete&id=<?php echo $u['id']; ?>" onclick="return confirm('Voulez-vous vraiment supprimer cet utilisateur ?')" class="text-red-400 hover:text-red-300 transition" title="Supprimer">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-600 cursor-not-allowed" title="Vous ne pouvez pas vous supprimer"><i data-lucide="trash-2" class="w-4 h-4"></i></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
elseif ($page === 'blog') {
    $articles = get_json_data('blog-articles.json');
    // Tri par date (plus récent en premier)
    usort($articles, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
    ?>
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Gestion du Blog</h2>
        <a href="gestion-blog.php" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg transition text-sm flex items-center">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i> Rédiger un article
        </a>
    </div>

    <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow-lg">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-gray-700/50 text-gray-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3">Image</th>
                        <th class="px-6 py-3">Titre</th>
                        <th class="px-6 py-3">Catégorie</th>
                        <th class="px-6 py-3">Date</th>
                        <th class="px-6 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700 text-sm text-gray-300">
                    <?php if (empty($articles)): ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">Aucun article trouvé.</td></tr>
                    <?php else: ?>
                        <?php foreach($articles as $a): 
                            // Gestion image externe ou locale
                            $img = (strpos($a['image'], 'http') === 0) ? $a['image'] : asset(str_replace('../', '', $a['image']));
                        ?>
                        <tr class="hover:bg-gray-700/30 transition duration-150">
                            <td class="px-6 py-4">
                                <img src="<?php echo $img; ?>" class="w-12 h-12 rounded-lg object-cover bg-gray-600 border border-gray-600">
                            </td>
                            <td class="px-6 py-4 font-medium text-white">
                                <?php echo e($a['title']); ?>
                                <span class="block text-xs text-gray-500 font-normal mt-1">Par <?php echo e($a['author']); ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 bg-blue-900/30 text-blue-400 rounded text-xs border border-blue-900/50">
                                    <?php echo e($a['category']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-400 text-xs">
                                <?php echo date("d/m/Y", strtotime($a['date'])); ?>
                            </td>
                            <td class="px-6 py-4 flex items-center gap-3">
                                <a href="gestion-blog.php?action=edit&slug=<?php echo $a['slug']; ?>" class="text-blue-400 hover:text-blue-300 transition" title="Éditer">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </a>
                                <a href="../page/article.php?slug=<?php echo $a['slug']; ?>" target="_blank" class="text-gray-400 hover:text-white transition" title="Voir">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </a>
                                <a href="gestion-blog.php?action=delete&slug=<?php echo $a['slug']; ?>" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet article ?')" class="text-red-400 hover:text-red-300 transition" title="Supprimer">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
elseif ($page === 'reviews') {
    $avis = get_json_data('avis.json');
    ?>
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Avis Clients</h2>
        <a href="gestion_avis.php" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg transition text-sm flex items-center">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i> Ajouter un avis
        </a>
    </div>

    <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow-lg">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-gray-700/50 text-gray-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3">Client</th>
                        <th class="px-6 py-3">Note</th>
                        <th class="px-6 py-3">Commentaire</th>
                        <th class="px-6 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700 text-sm text-gray-300">
                    <?php if (empty($avis)): ?>
                        <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">Aucun avis enregistré.</td></tr>
                    <?php else: ?>
                        <?php foreach($avis as $a): 
                            $id = $a['id'] ?? ''; // Gestion des anciens avis sans ID
                        ?>
                        <tr class="hover:bg-gray-700/30 transition duration-150">
                            <td class="px-6 py-4 font-medium text-white">
                                <?php echo e($a['nom']); ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex text-yellow-500">
                                    <?php 
                                    $note = (int)($a['note'] ?? 0);
                                    for($i=0; $i<5; $i++) {
                                        echo ($i < $note) 
                                            ? '<i data-lucide="star" class="w-4 h-4 fill-current"></i>' 
                                            : '<i data-lucide="star" class="w-4 h-4 text-gray-600"></i>';
                                    }
                                    ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-400 italic max-w-md truncate">
                                "<?php echo e($a['commentaire']); ?>"
                            </td>
                            <td class="px-6 py-4 flex items-center gap-3">
                                <a href="gestion_avis.php?action=edit&id=<?php echo $id; ?>" class="text-blue-400 hover:text-blue-300 transition" title="Éditer">
                                    <i data-lucide="edit-2" class="w-4 h-4"></i>
                                </a>
                                <a href="gestion_avis.php?action=delete&id=<?php echo $id; ?>" onclick="return confirm('Supprimer cet avis ?')" class="text-red-400 hover:text-red-300 transition" title="Supprimer">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
$response['html'] = ob_get_clean();
echo json_encode($response);
?>