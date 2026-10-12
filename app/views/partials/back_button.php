<button
    type="button"
    class="global-back-btn <?php echo !empty($workspaceBackButton) ? 'workspace-back-btn' : ''; ?>"
    data-fallback="<?php echo htmlspecialchars($backButtonFallback); ?>"
    aria-label="<?php echo htmlspecialchars($backButtonLabel); ?>"
>
    <i class="fa-solid fa-arrow-left"></i>
    <span><?php echo htmlspecialchars($backButtonLabel); ?></span>
</button>
