</main>

<!-- Footer -->
<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="footer-logo">Job<span>Nexus</span></div>
        <p class="footer-desc">
          Connecting talented professionals with world-class companies.
          Find your dream job or discover the perfect candidate.
        </p>
        <div class="footer-social mt-3">
          <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
          <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
          <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
          <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
        </div>
      </div>

      <div class="footer-column">
        <h4 class="footer-title">For Job Seekers</h4>
        <ul class="footer-links">
          <li><a href="<?php echo BASE_URL; ?>/jobs/">Browse Jobs</a></li>
          <li><a href="<?php echo BASE_URL; ?>/companies.php">Companies</a></li>
          <li><a href="<?php echo BASE_URL; ?>/seeker/saved-jobs.php">Saved Jobs</a></li>
          <li><a href="<?php echo BASE_URL; ?>/seeker/profile.php">Resume Builder</a></li>
          <li><a href="#">Career Advice</a></li>
        </ul>
      </div>

      <div class="footer-column">
        <h4 class="footer-title">For Employers</h4>
        <ul class="footer-links">
          <li><a href="<?php echo BASE_URL; ?>/auth/register.php?role=hr">Post a Job</a></li>
          <li><a href="#">Pricing</a></li>
          <li><a href="#">Talent Search</a></li>
          <li><a href="#">Employer Branding</a></li>
          <li><a href="#">Recruiting Solutions</a></li>
        </ul>
      </div>

      <div class="footer-column">
        <h4 class="footer-title">Resources</h4>
        <ul class="footer-links">
          <li><a href="#">Help Center</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
          <li><a href="#">Contact Us</a></li>
          <li><a href="#">About Us</a></li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      <p class="footer-copyright">
        &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.
      </p>
      <div class="footer-social d-none d-md-flex">
        <a href="#">Privacy</a>
        <a href="#">Terms</a>
        <a href="#">Cookies</a>
      </div>
    </div>
  </div>
</footer>

<!-- Scripts -->
<script src="<?php echo JS_URL; ?>main.js"></script>

<?php if (isset($additionalJS)): ?>
  <?php foreach ($additionalJS as $js): ?>
    <script src="<?php echo $js; ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>

<script>
  // Base URL for JS
  const BASE_URL = '<?php echo BASE_URL; ?>';
</script>
</body>

</html>