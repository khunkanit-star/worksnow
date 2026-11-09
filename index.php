<?php
declare(strict_types=1);
try {
    require __DIR__ . '/app/bootstrap.php';
    require __DIR__ . '/app/actions.php';
    $page = (string)($_GET['page'] ?? ($user ? 'dashboard' : 'login'));
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try { handle_action(); }
        catch (DomainException $ex) { if ($db->inTransaction()) $db->rollBack(); $error = $ex->getMessage(); http_response_code(422); }
        catch (PDOException $ex) { if ($db->inTransaction()) $db->rollBack(); error_log('Worksnow database action: ' . $ex->getMessage()); $error = 'The change could not be saved. The record may have changed; refresh and try again.'; http_response_code(409); }
    }
    if (!$user && !in_array($page, ['login','register','request','track','document'], true)) go(['page'=>'login']);
    if ($user && in_array($page,['login','register'],true)) go();
    require __DIR__ . '/app/views.php';
    ob_start();
    try { render_page($page); }
    catch (DomainException $ex) { ob_clean(); http_response_code(404); echo '<div class="empty"><h2>Page unavailable</h2><p>' . e($ex->getMessage()) . '</p><a class="button" href="index.php">Go to workspace</a></div>'; }
    $content = ob_get_clean();
    render_layout($page, $content, $error);
} catch (Throwable $ex) {
    error_log('Worksnow: ' . $ex->getMessage());
    http_response_code(500);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Worksnow setup</title><h1>Worksnow needs a database connection</h1><p>Check the PHP PDO driver and database settings. Hosted deployments use WORKSNOW_DB_DSN, WORKSNOW_DB_USER and WORKSNOW_DB_PASSWORD environment variables. See README.md for setup instructions.</p></html>';
}
