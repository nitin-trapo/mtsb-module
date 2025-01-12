    <footer class="footer mt-auto py-3 bg-light">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-muted"> <?php echo date('Y'); ?> Sales Agent Portal</span>
            <div>
                <a href="<?php echo BASE_URL; ?>/privacy-policy.php" class="text-muted text-decoration-none me-3">Privacy Policy</a>
                <a href="<?php echo BASE_URL; ?>/terms-of-service.php" class="text-muted text-decoration-none">Terms of Service</a>
            </div>
        </div>
    </div>
</footer>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($use_datatables) && $use_datatables): ?>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/main.js"></script>

</body>
</html>
