<?php
// Redirect to the new revise page
require_once __DIR__ . '/../../config/paths.php';
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
    exit();
}
$quotation_id = (int)$_GET['id'];
header("Location: " . BASE_URL . "modules/quotations/revise_quotation.php?id=" . $quotation_id);
exit();
?>