<?php
$css = <<<CSS

/* Modal Styling */
.modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.4);
    align-items: center;
    justify-content: center;
}

.modal-content {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-lg);
    width: 100%;
    max-width: 500px;
    animation: slideUp 0.3s ease-out;
    display: flex;
    flex-direction: column;
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.125rem;
    color: var(--text-primary);
}

.modal-header .close {
    cursor: pointer;
    font-size: 1.25rem;
    color: var(--text-muted);
}

.modal-header .close:hover {
    color: var(--text-primary);
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
CSS;

file_put_contents('c:/xampp/htdocs/MiniERP/assets/css/style.css', $css, FILE_APPEND);
echo "Appended modal CSS";
?>
