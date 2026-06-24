<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

/*
|--------------------------------------------------------------------------
| CONNEXION POSTGRESQL
|--------------------------------------------------------------------------
*/
try {
    $pdo = new PDO(
        "pgsql:host=shinkansen.proxy.rlwy.net;port=34594;dbname=meter_payment",
        "postgres",
        "hyTbDLBOtPSKtKQnsVkOFBtoFnCLdByq"
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {
    die("Erreur connexion : " . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| NAVIGATION ET SÉCURITÉ
|--------------------------------------------------------------------------
*/
$panel = $_GET['panel'] ?? $_POST['panel'] ?? 'transactions';
$panel = in_array($panel, ['transactions', 'settings'], true) ? $panel : 'transactions';
$isAjaxTransactionRequest = $panel === 'transactions'
    && ($_GET['ajax'] ?? '') === 'transactions';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function isEnabledSetting($value): bool
{
    return $value === true
        || $value === 1
        || in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
}

/*
|--------------------------------------------------------------------------
| CRUD PAYMENT_SETTINGS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $panel === 'settings') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Session expirée. Veuillez réessayer.'];
        header('Location: transactions.php?panel=settings');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

            if ($id === false || $id === null || $id < 1) {
                throw new InvalidArgumentException('L’identifiant doit être un entier positif.');
            }

            $stmt = $pdo->prepare('INSERT INTO payment_settings (id, zamak) VALUES (:id, :zamak)');
            $stmt->execute([
                'id' => $id,
                'zamak' => isset($_POST['zamak']) ? 'true' : 'false',
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Paramètre ajouté avec succès.'];
        } elseif ($action === 'update') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $originalId = filter_input(INPUT_POST, 'original_id', FILTER_VALIDATE_INT);

            if ($id === false || $id === null || $id < 1 || $originalId === false || $originalId === null) {
                throw new InvalidArgumentException('Identifiant invalide.');
            }

            $stmt = $pdo->prepare(
                'UPDATE payment_settings SET id = :id, zamak = :zamak WHERE id = :original_id'
            );
            $stmt->execute([
                'id' => $id,
                'zamak' => isset($_POST['zamak']) ? 'true' : 'false',
                'original_id' => $originalId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Ce paramètre n’existe plus.');
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Paramètre modifié avec succès.'];
        } elseif ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

            if ($id === false || $id === null) {
                throw new InvalidArgumentException('Identifiant invalide.');
            }

            $stmt = $pdo->prepare('DELETE FROM payment_settings WHERE id = :id');
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Ce paramètre n’existe plus.');
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Paramètre supprimé avec succès.'];
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
    }

    header('Location: transactions.php?panel=settings');
    exit;
}

/*
|--------------------------------------------------------------------------
| DONNÉES DU PANNEAU ACTIF
|--------------------------------------------------------------------------
*/
$data = [];
$statusList = [];
$settings = [];
$stats = ['total' => 0, 'montant' => 0];
$total_resultats = 0;

if ($panel === 'transactions') {
    $filters = [];
    $params = [];

    $mobile_number  = trim($_GET['mobile_number'] ?? '');
    $customer_code  = trim($_GET['customer_code'] ?? '');
    $transaction_id = trim($_GET['transaction_id'] ?? '');
    $token_code     = trim($_GET['token_code'] ?? '');
    $statuses       = $_GET['status'] ?? [];
    $payment_method = trim($_GET['payment_method'] ?? '');
    $date_de        = trim($_GET['date_de'] ?? '');
    $date_a         = trim($_GET['date_a'] ?? '');

    if (!is_array($statuses)) {
        $statuses = [];
    }

    $textFilters = [
        'mobile_number' => $mobile_number,
        'customer_code' => $customer_code,
        'transaction_id' => $transaction_id,
        'token_code' => $token_code,
    ];

    foreach ($textFilters as $column => $value) {
        if ($value !== '') {
            $filters[] = "$column ILIKE :$column";
            $params[$column] = "%$value%";
        }
    }

    if ($statuses !== []) {
        $placeholders = [];
        foreach (array_values($statuses) as $index => $status) {
            $key = 'status' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }
        $filters[] = 'status IN (' . implode(',', $placeholders) . ')';
    }

    if ($payment_method !== '') {
        $filters[] = 'payment_method = :payment_method';
        $params['payment_method'] = $payment_method;
    }
    if ($date_de !== '') {
        $filters[] = 'DATE(created_at) >= :date_de';
        $params['date_de'] = $date_de;
    }
    if ($date_a !== '') {
        $filters[] = 'DATE(created_at) <= :date_a';
        $params['date_a'] = $date_a;
    }

    $where = $filters === [] ? '' : ' WHERE ' . implode(' AND ', $filters);
    if (!$isAjaxTransactionRequest) {
        $statusList = $pdo->query(
            'SELECT DISTINCT status FROM transactions WHERE status IS NOT NULL ORDER BY status'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    $stmtStats = $pdo->prepare(
        "SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS montant FROM transactions$where"
    );
    $stmtStats->execute($params);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM transactions$where ORDER BY id DESC LIMIT 50");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_resultats = count($data);
} else {
    $settings = $pdo->query('SELECT id, zamak FROM payment_settings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

if ($isAjaxTransactionRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => (int) $stats['total'],
            'montant' => (float) $stats['montant'],
        ],
        'displayed' => $total_resultats,
        'transactions' => $data,
        'updated_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Administration des paiements</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<style>

body{
    background:#f4f7fc;
    font-family:'Segoe UI',sans-serif;
}

.page-title{
    font-size:32px;
    font-weight:700;
    color:#1e293b;
}

.card{
    border:none;
    border-radius:18px;
    box-shadow:0 5px 20px rgba(0,0,0,.08);
}

.kpi{
    border-radius:18px;
    padding:25px;
    color:white;
}

.kpi h2{
    margin:0;
    font-size:30px;
    font-weight:700;
}

.kpi p{
    margin:0;
    opacity:.9;
}

.kpi-blue{
    background:linear-gradient(135deg,#2563eb,#1e40af);
}

.kpi-green{
    background:linear-gradient(135deg,#10b981,#047857);
}

.table thead{
    background:#0f172a;
    color:white;
}

.badge-success{
    background:#16a34a;
}

.badge-danger{
    background:#dc2626;
}

.badge-warning{
    background:#f59e0b;
}

.btn-primary{
    background:#2563eb;
    border:none;
}

.btn-secondary{
    border:none;
}

.form-control,
.form-select{
    border-radius:12px;
}

.dataTables_wrapper .dataTables_filter{
    display:none;
}

.panel-tabs{
    display:flex;
    gap:10px;
    padding:8px;
    background:white;
    border-radius:16px;
    box-shadow:0 5px 20px rgba(0,0,0,.06);
}

.panel-tabs .nav-link{
    color:#475569;
    border-radius:11px;
    font-weight:600;
    padding:11px 18px;
}

.panel-tabs .nav-link.active{
    color:white;
    background:#2563eb;
}

.setting-switch .form-check-input{
    width:3rem;
    height:1.5rem;
    cursor:pointer;
}

.setting-switch .form-check-label{
    cursor:pointer;
    margin-left:.5rem;
}

@media (max-width: 767px){
    .page-title{font-size:24px;}
    .panel-tabs{width:100%;}
    .panel-tabs .nav-link{flex:1;text-align:center;}
}

</style>

</head>

<body>
<div class="container-fluid py-4 px-lg-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">💳 Administration des paiements</h1>
            <p class="text-secondary mb-0">Transactions et configuration du service</p>
        </div>

        <nav class="panel-tabs" aria-label="Panneaux d’administration">
            <a href="transactions.php?panel=transactions"
               class="nav-link <?= $panel === 'transactions' ? 'active' : '' ?>">
                Transactions
            </a>
            <a href="transactions.php?panel=settings"
               class="nav-link <?= $panel === 'settings' ? 'active' : '' ?>">
                Paramètres
            </a>
        </nav>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    <?php endif; ?>

    <?php if ($panel === 'transactions'): ?>
        <div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-3">
            <span id="ajax-status" class="badge bg-light text-secondary border">● Actualisation automatique active</span>
            <span id="displayed-count" class="badge bg-dark fs-6">
                <?= number_format($total_resultats) ?> résultat(s) affiché(s)
            </span>
        </div>

        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <div class="kpi kpi-blue">
                    <p>Total des transactions filtrées</p>
                    <h2 id="stats-total"><?= number_format((int) $stats['total']) ?></h2>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="kpi kpi-green">
                    <p>Montant total filtré</p>
                    <h2 id="stats-amount">$<?= number_format((float) $stats['montant'], 2) ?></h2>
                </div>
            </div>
        </div>

        <div class="card p-4 mb-4">
            <form method="GET" action="transactions.php">
                <input type="hidden" name="panel" value="transactions">
                <div class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="mobile_number" class="form-control"
                               placeholder="Numéro mobile" value="<?= htmlspecialchars($mobile_number) ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="customer_code" class="form-control"
                               placeholder="Code client" value="<?= htmlspecialchars($customer_code) ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="transaction_id" class="form-control"
                               placeholder="ID transaction" value="<?= htmlspecialchars($transaction_id) ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="token_code" class="form-control"
                               placeholder="Code token" value="<?= htmlspecialchars($token_code) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-secondary small">Date de début</label>
                        <input type="date" name="date_de" class="form-control"
                               value="<?= htmlspecialchars($date_de) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-secondary small">Date de fin</label>
                        <input type="date" name="date_a" class="form-control"
                               value="<?= htmlspecialchars($date_a) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-secondary small">Statuts</label>
                        <select name="status[]" class="form-select select2" multiple>
                            <?php foreach ($statusList as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>"
                                    <?= in_array($status, $statuses, true) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-secondary small">Méthode de paiement</label>
                        <input type="text" name="payment_method" class="form-control"
                               placeholder="Méthode de paiement" value="<?= htmlspecialchars($payment_method) ?>">
                    </div>
                    <div class="col-md-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">🔍 Filtrer</button>
                        <a href="transactions.php?panel=transactions" class="btn btn-secondary">♻ Réinitialiser</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="card p-3">
            <div class="table-responsive">
                <table id="transactions-table" class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Transaction</th>
                            <th>Mobile</th>
                            <th>Client</th>
                            <th>Montant</th>
                            <th>Approuvé</th>
                            <th>Statut</th>
                            <th>Paiement</th>
                            <th>Token</th>
                            <th>Expiré</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): ?>
                            <?php
                            $statusValue = strtolower((string) ($row['status'] ?? ''));
                            $statusClass = [
                                'success' => 'bg-success',
                                'failed' => 'bg-danger',
                                'pending' => 'bg-warning text-dark',
                            ][$statusValue] ?? 'bg-secondary';
                            ?>
                            <tr>
                                <td><?= (int) $row['id'] ?></td>
                                <td><?= htmlspecialchars($row['transaction_id'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['mobile_number'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                <td>$<?= number_format((float) ($row['amount'] ?? 0), 2) ?></td>
                                <td>
                                    <?= isset($row['approved_amount'])
                                        ? number_format((float) $row['approved_amount'], 2)
                                        : '' ?>
                                </td>
                                <td>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= htmlspecialchars($row['status'] ?? '') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['payment_method'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['token_code'] ?? '') ?></td>
                                <td>
                                    <?php if (array_key_exists('expire_token_code', $row)): ?>
                                        <span class="badge <?= isEnabledSetting($row['expire_token_code']) ? 'bg-danger' : 'bg-success' ?>">
                                            <?= isEnabledSetting($row['expire_token_code']) ? 'Oui' : 'Non' ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary btn-check-payment"
                                            data-transaction="<?= htmlspecialchars($row['transaction_id'] ?? '') ?>">
                                        🔍 Vérifier
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <?php
        $enabledCount = count(array_filter($settings, static function ($setting) {
            return isEnabledSetting($setting['zamak'] ?? false);
        }));
        ?>
        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <div class="kpi kpi-blue">
                    <p>Total des paramètres</p>
                    <h2><?= count($settings) ?></h2>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="kpi kpi-green">
                    <p>Paramètres Zamak actifs</p>
                    <h2><?= $enabledCount ?></h2>
                </div>
            </div>
        </div>

        <div class="card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h5 mb-1">Ajouter un paramètre</h2>
                    <p class="text-secondary mb-0">Table <code>payment_settings</code></p>
                </div>
            </div>

            <form method="POST" action="transactions.php?panel=settings" class="row g-3 align-items-end">
                <input type="hidden" name="panel" value="settings">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="col-md-5">
                    <label for="new-id" class="form-label">ID</label>
                    <input id="new-id" type="number" name="id" min="1" step="1"
                           class="form-control" required placeholder="Ex. 2">
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch setting-switch mb-2">
                        <input id="new-zamak" class="form-check-input" type="checkbox" name="zamak" value="1">
                        <label class="form-check-label" for="new-zamak">Zamak activé</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">＋ Ajouter</button>
                </div>
            </form>
        </div>

        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center px-2 pt-2 mb-3">
                <h2 class="h5 mb-0">Liste des paramètres</h2>
                <span class="badge bg-dark"><?= count($settings) ?> ligne(s)</span>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:30%">ID</th>
                            <th style="width:35%">Zamak</th>
                            <th style="width:35%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settings as $setting): ?>
                            <?php
                            $settingId = (int) $setting['id'];
                            $settingEnabled = isEnabledSetting($setting['zamak']);
                            $updateFormId = 'update-setting-' . $settingId;
                            ?>
                            <tr>
                                <td>
                                    <form id="<?= $updateFormId ?>" method="POST"
                                          action="transactions.php?panel=settings">
                                        <input type="hidden" name="panel" value="settings">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="original_id" value="<?= $settingId ?>">
                                    </form>
                                    <input type="number" name="id" min="1" step="1" required
                                           class="form-control" value="<?= $settingId ?>"
                                           form="<?= $updateFormId ?>" aria-label="ID du paramètre">
                                </td>
                                <td>
                                    <div class="form-check form-switch setting-switch">
                                        <input id="zamak-<?= $settingId ?>" class="form-check-input"
                                               type="checkbox" name="zamak" value="1"
                                               form="<?= $updateFormId ?>" <?= $settingEnabled ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="zamak-<?= $settingId ?>">
                                            <?= $settingEnabled ? 'Activé' : 'Désactivé' ?>
                                        </label>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="submit" form="<?= $updateFormId ?>"
                                                class="btn btn-sm btn-primary">Enregistrer</button>

                                        <form method="POST" action="transactions.php?panel=settings"
                                              class="delete-setting-form">
                                            <input type="hidden" name="panel" value="settings">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="id" value="<?= $settingId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($settings === []): ?>
                            <tr>
                                <td colspan="3" class="text-center text-secondary py-5">
                                    Aucun paramètre enregistré.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(function () {
    let transactionDataTable = null;
    let refreshInProgress = false;
    const transactionTable = document.querySelector('#transactions-table');

    if (transactionTable) {
        transactionDataTable = new DataTable(transactionTable, {
            pageLength: 50,
            order: [[0, 'desc']],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/fr-FR.json'
            }
        });

        $('.select2').select2({
            placeholder: 'Choisir un ou plusieurs statuts',
            allowClear: true,
            width: '100%'
        });
    }

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = String(value ?? '');
        return element.innerHTML;
    }

    function isTrue(value) {
        return value === true
            || value === 1
            || ['1', 't', 'true', 'yes', 'on'].includes(String(value ?? '').toLowerCase());
    }

    function formatNumber(value, decimals = 0) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(Number(value || 0));
    }

    function transactionRows(transactions) {
        return transactions.map((row) => {
            const status = String(row.status ?? '');
            const statusClasses = {
                success: 'bg-success',
                failed: 'bg-danger',
                pending: 'bg-warning text-dark'
            };
            const statusClass = statusClasses[status.toLowerCase()] || 'bg-secondary';
            const expired = isTrue(row.expire_token_code);
            const transactionId = escapeHtml(row.transaction_id);

            return [
                Number(row.id || 0),
                transactionId,
                escapeHtml(row.mobile_number),
                escapeHtml(row.customer_code),
                '$' + formatNumber(row.amount, 2),
                row.approved_amount === null || row.approved_amount === undefined
                    ? ''
                    : formatNumber(row.approved_amount, 2),
                '<span class="badge ' + statusClass + '">' + escapeHtml(status) + '</span>',
                escapeHtml(row.payment_method),
                escapeHtml(row.token_code),
                '<span class="badge ' + (expired ? 'bg-danger' : 'bg-success') + '">' +
                    (expired ? 'Oui' : 'Non') + '</span>',
                escapeHtml(row.created_at),
                '<button type="button" class="btn btn-sm btn-primary btn-check-payment" ' +
                    'data-transaction="' + transactionId + '">🔍 Vérifier</button>'
            ];
        });
    }

    async function refreshTransactions() {
        if (!transactionDataTable || refreshInProgress || document.hidden || Swal.isVisible()) {
            return;
        }

        refreshInProgress = true;
        const statusElement = document.querySelector('#ajax-status');

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('panel', 'transactions');
            url.searchParams.set('ajax', 'transactions');

            const response = await fetch(url.toString(), {
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            });
            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Actualisation impossible.');
            }

            transactionDataTable.clear();
            transactionDataTable.rows.add(transactionRows(payload.transactions));
            transactionDataTable.draw(false);

            document.querySelector('#stats-total').textContent = formatNumber(payload.stats.total);
            document.querySelector('#stats-amount').textContent = '$' + formatNumber(payload.stats.montant, 2);
            document.querySelector('#displayed-count').textContent =
                formatNumber(payload.displayed) + ' résultat(s) affiché(s)';

            if (statusElement) {
                const updatedAt = new Date(payload.updated_at).toLocaleTimeString('fr-FR');
                statusElement.className = 'badge bg-light text-success border';
                statusElement.textContent = '● Mis à jour à ' + updatedAt;
            }
        } catch (error) {
            if (statusElement) {
                statusElement.className = 'badge bg-light text-danger border';
                statusElement.textContent = '● Actualisation interrompue';
            }
            console.warn('Actualisation AJAX :', error.message);
        } finally {
            refreshInProgress = false;
        }
    }

    $(document).on('click', '.btn-check-payment', async function () {
        const transactionId = String($(this).data('transaction') || '');

        Swal.fire({
            title: 'Vérification...',
            text: 'Connexion à FlexPay',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        try {
            const response = await fetch(
                'https://greentech-be-production-4540.up.railway.app/api/payments/flexpay/check-z/' +
                encodeURIComponent(transactionId)
            );
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'La vérification a échoué.');
            }

            Swal.fire({
                icon: 'success',
                title: 'Résultat',
                html: '<pre id="payment-result" class="text-start"></pre>',
                didOpen: () => {
                    document.querySelector('#payment-result').textContent = JSON.stringify(data, null, 2);
                }
            });
        } catch (error) {
            Swal.fire({icon: 'error', title: 'Erreur', text: error.message});
        }
    });

    $(document).on('submit', '.delete-setting-form', function (event) {
        event.preventDefault();
        const form = this;

        Swal.fire({
            icon: 'warning',
            title: 'Supprimer ce paramètre ?',
            text: 'Cette action est irréversible.',
            showCancelButton: true,
            confirmButtonText: 'Oui, supprimer',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#dc2626'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });

    $(document).on('change', '.setting-switch input[type="checkbox"]', function () {
        const label = document.querySelector('label[for="' + this.id + '"]');
        if (label && this.id !== 'new-zamak') {
            label.textContent = this.checked ? 'Activé' : 'Désactivé';
        }
    });

    <?php if ($panel === 'transactions'): ?>
    setInterval(refreshTransactions, 10000);
    <?php endif; ?>
});
</script>
</body>
</html>
