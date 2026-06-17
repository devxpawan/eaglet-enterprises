<?php
// This file is deprecated - revisions are now created via process_revise_quotation.php
require_once __DIR__ . '/../../config/paths.php';
session_start();
if (isset($_POST['quotation_id'])) {
    $_SESSION['quotation_error'] = "Editing quotations is no longer available. Please use 'Revise' to create a new revision.";
    header("Location: " . BASE_URL . "modules/quotations/revise_quotation.php?id=" . (int)$_POST['quotation_id']);
} else {
    header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
}
exit();
?>