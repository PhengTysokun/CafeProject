<?php include('config.php'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bird's Nest Coffee | Premium Coffee Experience</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
  <style>
    /* ── RESET ── */
    html, body {
      overflow-x: hidden;
      width: 100%;
      max-width: 100%;
    }
    
    /* ── HEADER ── */
    header {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 72px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 40px;
      background: rgba(18, 18, 18, 0.95);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      z-index: 1100;
      box-shadow: 0 2px 20px rgba(0,0,0,0.3);
      border-bottom: 1px solid rgba(255,255,255,0.05);
    }

    .logo { 
      display: flex;
      align-items: center;
      gap: 12px;
      /* Fixed width for logo */
      width: 200px;
    }
    
    .logo img {
      height: 50px;
      width: 50px;
      border-radius: 50%;
      display: block;
      box-shadow: 0 2px 10px rgba(0,0,0,0.2);
      object-fit: cover;
    }
    
    .logo .brand-name {
      color: #FFFFFF;
      font-size: 20px;
      font-weight: 700;
      white-space: nowrap;
    }
    
    .logo .brand-name i {
      
    }

    nav {
      display: flex;
      align-items: center;
      gap: 32px;
      /* This makes nav take up remaining space */
      flex: 1;
      /* This centers the nav items within the remaining space */
      justify-content: center;
    }

    nav a {
      color: #FFFFFF;
      text-decoration: none;
      font-weight: 500;
      font-size: 15px;
      padding: 6px 0;
      transition: all 0.3s ease;
      position: relative;
      white-space: nowrap;
    }

    nav a::after {
      content: '';
      position: absolute;
      bottom: -2px;
      left: 0;
      width: 0;
      height: 2px;
      background: #F39C12;
      transition: width 0.3s ease;
    }

    nav a:hover { color: #F39C12; }
    nav a:hover::after { width: 100%; }

    .header-right {
      /* Fixed width for right side */
      width: 200px;
      display: flex;
      justify-content: flex-end;
    }

    .header-right i {
      font-size: 20px;
      color: #FFFFFF;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .header-right i:hover {
      color: #F39C12;
      transform: translateY(-2px);
    }

    /* ── HERO (PARALLAX) ── */
    .hero {
      position: relative;
      height: 100vh;
      min-height: 600px;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      background-image: url('images/background.png');
      background-size: cover;
      background-position: center;
      background-attachment: fixed;
      background-repeat: no-repeat;
      overflow: hidden;
    }

    .hero::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      z-index: 1;
    }

    .hero-content {
      position: relative;
      z-index: 2;
      padding: 20px;
      max-width: 800px;
    }

    .hero-content h1 {
      font-size: 4rem;
      font-weight: 800;
      color: #FFFFFF;
      margin-bottom: 10px;
      letter-spacing: 2px;
      opacity: 0;
      animation: typewriterFade 2.5s ease forwards;
      animation-delay: 0.5s;
    }

    @keyframes typewriterFade {
      0% {
        opacity: 0;
        transform: translateY(30px);
        letter-spacing: 8px;
        filter: blur(4px);
      }
      60% {
        opacity: 1;
        transform: translateY(0);
        letter-spacing: 4px;
        filter: blur(0);
      }
      100% {
        opacity: 1;
        transform: translateY(0);
        letter-spacing: 2px;
        filter: blur(0);
      }
    }

    .hero-content p {
      font-size: 1.2rem;
      color: #FFFFFF;
      margin-bottom: 30px;
      opacity: 0;
      animation: fadeUp 1s ease forwards;
      animation-delay: 2s;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .hero-buttons {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 16px;
      margin-top: 8px;
      flex-wrap: wrap;
      opacity: 0;
      animation: fadeUp 1s ease forwards;
      animation-delay: 2.5s;
    }

    .hero-buttons .btn {
      display: inline-block;
      padding: 14px 40px;
      border-radius: 50px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease;
      font-size: 16px;
      box-shadow: 0 4px 15px rgba(243,156,18,0.25);
      position: relative;
      overflow: hidden;
    }

    .hero-buttons .btn-primary {
      background: #F39C12;
      color: #FFFFFF;
    }

    .hero-buttons .btn-primary:hover {
      background: #D35400;
      transform: translateY(-3px);
      box-shadow: 0 8px 30px rgba(243,156,18,0.35);
    }

    .hero-buttons .btn-secondary {
      background: transparent;
      color: #FFFFFF;
      border: 2px solid #FFFFFF;
    }

    .hero-buttons .btn-secondary:hover {
      background: #FFFFFF;
      color: #1E1E1E;
      transform: translateY(-3px);
      box-shadow: 0 4px 20px rgba(255,255,255,0.2);
    }

    /* ── SECTIONS (Reveal Animation) ── */
    section {
      padding: 80px 40px;
      opacity: 0;
      transform: translateY(60px);
      transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
    }

    section.visible {
      opacity: 1;
      transform: translateY(0);
    }

    /* ── ABOUT ── */
    .about-subtitle {
      color: #888888;
      font-size: 16px;
      margin-top: -20px;
      margin-bottom: 30px;
      text-align: center;
    }

    .about-tag {
      display: inline-block;
      background: rgba(209, 144, 75, 0.15);
      color: #d1904b;
      padding: 4px 16px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 8px;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .about-stats {
      display: flex;
      gap: 40px;
      margin: 20px 0 24px;
    }

    .stat {
      text-align: center;
    }

    .stat-number {
      font-size: 32px;
      font-weight: 700;
      color: #d1904b;
    }

    .stat-label {
      font-size: 14px;
      color: #888888;
      margin-top: 2px;
    }

    .small-btn i {
      margin-left: 6px;
      transition: transform 0.3s ease;
    }

    .small-btn:hover i {
      transform: translateX(4px);
    }

    /* ── MENU ── */
    .menu-title { text-align: center; margin-bottom: 40px; }
    .menu-title h2 { font-size: 2.5rem; color: #FFFFFF; }
    .menu-title .accent { color: #F39C12; }

    .menu-panel {
      background: #1E1E1E;
      border-radius: 20px;
      padding: 40px;
      box-shadow: 0 10px 50px rgba(0,0,0,0.3);
    }

    .recommended-header {
      text-align: center;
      margin: 0 0 20px;
    }

    .recommended-header h3 {
      color: #F39C12;
      font-size: 24px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 2px;
      position: relative;
      display: inline-block;
      padding-bottom: 8px;
    }

    .recommended-header h3::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 60px;
      height: 2px;
      background: #F39C12;
    }

    .recommended-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 25px;
      margin-bottom: 20px;
    }

    .recommended-card {
      background: #2A2A2A;
      border-radius: 16px;
      padding: 16px;
      text-align: center;
      border: 2px solid #F39C12;
      transition: all 0.4s ease;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      align-items: center;
      height: 100%;
      min-height: 340px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.3);
      position: relative;
    }

    .recommended-card:hover {
      border-color: #F39C12;
      box-shadow: 0 0 12px rgba(243, 156, 18, 0.4), 0 8px 30px rgba(0,0,0,0.4);
      transform: translateY(-6px);
    }

    .recommended-card .recommended-badge {
      position: absolute;
      top: 10px;
      right: 10px;
      background: #F39C12;
      color: #000;
      font-size: 10px;
      font-weight: 700;
      padding: 4px 12px;
      border-radius: 20px;
      text-transform: uppercase;
      box-shadow: 0 2px 10px rgba(243, 156, 18, 0.3);
      z-index: 10;
      pointer-events: none;
    }

    .recommended-card img {
      width: 100%;
      height: 180px;
      object-fit: cover;
      border-radius: 12px;
      transition: transform 0.5s ease;
      margin-bottom: 12px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .recommended-card:hover img { transform: scale(1.06); }

    .recommended-card h3 {
      color: #FFFFFF;
      font-size: 17px;
      font-weight: 600;
      margin-bottom: 4px;
      min-height: 52px;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 0 8px;
    }

    .recommended-card:hover h3 { color: #F39C12; }

    .recommended-card .price {
      color: #E67E22;
      font-weight: 700;
      font-size: 18px;
      margin-top: auto;
    }

    .menu-footer { text-align: center; margin-top: 30px; }
    .menu-footer .view-all {
      display: inline-block;
      padding: 14px 50px;
      border: 2px solid #F39C12;
      border-radius: 50px;
      color: #F39C12;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s ease;
      font-size: 16px;
    }

    .menu-footer .view-all:hover {
      background: #F39C12;
      color: #1E1E1E;
      transform: translateY(-3px);
      box-shadow: 0 8px 30px rgba(243,156,18,0.3);
    }

    .menu-footer .view-all i { margin-right: 8px; }

    /* ── CONTACT ── */
    .contact-subtitle {
      color: #888888;
      font-size: 16px;
      text-align: center;
      margin-top: -20px;
      margin-bottom: 40px;
    }

    .contact-wrap {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 40px;
      max-width: 1000px;
      margin: 0 auto;
    }

    .contact-panel {
      background: #1E1E1E;
      border-radius: 16px;
      padding: 32px;
    }

    .contact-info-item {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 12px 16px;
      border-radius: 12px;
      transition: all 0.3s ease;
    }

    .contact-info-item:hover { background: rgba(209, 144, 75, 0.05); }

    .contact-info-item i {
      font-size: 22px;
      color: #d1904b;
      width: 40px;
      text-align: center;
    }

    .contact-info-item span { font-size: 15px; color: #FFFFFF; }

    .contact-social {
      display: flex;
      gap: 12px;
      margin-top: 20px;
    }

    .contact-social a {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: rgba(209, 144, 75, 0.1);
      color: #d1904b;
      transition: all 0.3s ease;
      text-decoration: none;
      font-size: 18px;
    }

    .contact-social a:hover {
      background: #d1904b;
      color: #000;
      transform: translateY(-3px);
    }

    .contact-form input,
    .contact-form textarea {
      width: 100%;
      padding: 14px 16px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.1);
      background: rgba(255,255,255,0.05);
      color: #FFFFFF;
      font-size: 14px;
      font-family: 'Poppins', sans-serif;
      transition: all 0.3s ease;
      outline: none;
    }

    .contact-form input:focus,
    .contact-form textarea:focus {
      border-color: #d1904b;
      box-shadow: 0 4px 20px rgba(209, 144, 75, 0.15);
    }

    .contact-form textarea { min-height: 120px; resize: vertical; }

    .contact-form .btn-submit {
      padding: 12px 40px;
      border: none;
      border-radius: 50px;
      background: #d1904b;
      color: #000;
      font-weight: 700;
      font-size: 15px;
      cursor: pointer;
      transition: all 0.3s ease;
      width: auto;
      min-width: 160px;
    }

    .contact-form .btn-submit:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 30px rgba(209, 144, 75, 0.3);
      filter: brightness(1.1);
    }

    .contact-form .btn-wrapper {
      display: flex;
      justify-content: center;
      margin-top: 4px;
    }

    /* ── FOOTER ── */
    footer {
      text-align: center;
      padding: 30px;
      color: #888888;
      border-top: 1px solid rgba(255,255,255,0.05);
    }

    footer span { color: #F39C12; }

    /* ── RESPONSIVE (Mobile) ── */
    @media (max-width: 768px) {
      header { padding: 0 20px; height: 64px; }
      nav { gap: 16px; }
      nav a { font-size: 13px; }
      .logo { width: auto; }
      .logo img { height: 40px; width: 40px; }
      .logo .brand-name { font-size: 16px; }
      .header-right { width: auto; }
      
      .hero-content h1 { font-size: 2.5rem; }
      .hero-content p { font-size: 1rem; }
      
      .menu-panel { padding: 24px 16px; }
      .recommended-grid { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; }
      .recommended-card { min-height: 280px; }
      .recommended-card img { height: 140px; }
      .recommended-card h3 { font-size: 14px; min-height: 40px; }
      .recommended-card .price { font-size: 15px; }
      .menu-title h2 { font-size: 2rem; }
      .hero-buttons { gap: 12px; }
      .hero-buttons .btn { padding: 12px 28px; font-size: 14px; }
      .about-stats { gap: 20px; justify-content: center; flex-wrap: wrap; }
      .stat-number { font-size: 20px; }
      
      .contact-wrap { grid-template-columns: 1fr; }
      .contact-info-item { padding: 10px 14px; }
      .contact-social { justify-content: center; }
      .contact-form .btn-submit { padding: 10px 32px; font-size: 14px; min-width: 140px; }
      
      section { padding: 60px 20px; }
    }

    @media (max-width: 480px) {
      header { height: 56px; padding: 0 12px; }
      nav { gap: 10px; }
      nav a { font-size: 11px; }
      .logo img { height: 32px; width: 32px; }
      .logo .brand-name { font-size: 13px; }
      
      .hero-content h1 { font-size: 1.8rem; }
      
      /* ── FIX: Mobile menu grid ── */
      .recommended-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }
      
      .recommended-card {
        min-height: 260px;
        padding: 12px;
      }
      
      .recommended-card img {
        height: 120px;
      }
      
      .recommended-card h3 {
        font-size: 14px;
        min-height: 40px;
      }
      
      .recommended-card .price {
        font-size: 15px;
      }
      
      .hero-buttons {
        flex-direction: column;
        gap: 10px;
      }
      .hero-buttons .btn {
        width: 100%;
        text-align: center;
      }
      .contact-form .btn-submit {
        padding: 10px 24px;
        font-size: 13px;
        min-width: 120px;
        width: 100%;
      }
    }
  </style>
</head>
<body>

<header>
  <div class="logo">
    <img src="images/Newlogo.jpg" alt="Bird's Nest Coffee Logo">
    <span class="brand-name">Bird's Nest</span>
  </div>
  <nav>
    <a href="index.php" class="active">Home</a>
    <a href="#about">About Us</a>
    <a href="menu.php">Menu</a>
    <a href="#contact">Contact</a>
  </nav>
  <div class="header-right">
    <!-- Empty placeholder -->
  </div>
</header>

<!-- ── HERO (PARALLAX + ANIMATED TEXT) ── -->
<section class="hero">
  <div class="hero-content">
    <h1>WELCOME TO <br> BIRD'S NEST<br>COFFEE!</h1>
    <p>We're Open Daily from 6:00 AM to 8:00 PM ☕</p>
    
    <div class="hero-buttons">
      <a href="#menu" class="btn btn-primary">See Our Menu</a>
      <a href="menu.php" class="btn btn-secondary">Order Here</a>
    </div>
  </div>
</section>

<!-- ── ABOUT (COUNTER ANIMATION) ── -->
<section id="about">
  <div class="about-title">
    <h1>ABOUT <span>US</span></h1>
    <p class="about-subtitle">Discover what makes Bird's Nest Coffee special</p>
  </div>

  <div class="about-container">
    <div class="about-image">
      <img src="images/about.png" alt="About Bird's Nest Coffee" style="width:100%; max-width:500px; border-radius:16px; display:block; margin:0 auto;">
    </div>
    <div class="about-text" style="max-width:600px; margin:20px auto 0; text-align:center;">
      <span class="about-tag">Our Story</span>
      <h2>Welcome To Bird's Nest Coffee!</h2>
      <p>At Bird's Nest Coffee, we are passionate about coffee and believe every cup tells a story. 
      We are a cozy café nestled in the heart of the city, offering comfort, flavor, and warmth in every sip.</p>
      <p>Our inviting atmosphere is designed to be a haven for coffee lovers who seek a peaceful moment to relax, connect, and enjoy.</p>
      
      <!-- Stats with data attributes for counter -->
      <div class="about-stats">
        <div class="stat">
          <div class="stat-number" data-target="5">0</div>
          <div class="stat-label">Years of Service</div>
        </div>
        <div class="stat">
          <div class="stat-number" data-target="10000">0</div>
          <div class="stat-label">Happy Customers</div>
        </div>
        <div class="stat">
          <div class="stat-number" data-target="50">0</div>
          <div class="stat-label">Unique Drinks</div>
        </div>
      </div>
      
      <a href="#" class="btn small-btn">Learn More <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<!-- ── MENU (SIMPLIFIED - 6 DRINKS ONLY) ── -->
<section id="menu">
  <div class="menu-title">
    <h2>OUR <span class="accent">MENU</span></h2>
    <p style="color:#888888; font-size:16px; margin-top:-10px;">Handpicked just for you</p>
  </div>

  <div class="menu-panel">
    
    <!-- ── TOP 6 DRINKS (NO CATEGORIES) ── -->
    <?php
    // Get top 6 recommended drinks (by latest or bestseller)
    $recommended_query = "SELECT * FROM products ORDER BY product_id DESC LIMIT 6";
    $recommended_result = mysqli_query($conn, $recommended_query);
    
    if ($recommended_result && mysqli_num_rows($recommended_result) > 0):
    ?>
    <div class="recommended-header">
      <h3><i class="fa-solid fa-star"></i> Bestsellers</h3>
    </div>
    
    <div class="recommended-grid">
      <?php while ($rec = mysqli_fetch_assoc($recommended_result)): ?>
        <div class="recommended-card">
          <div class="recommended-badge">★ Popular</div>
          <img src="<?php echo htmlspecialchars($rec['image']); ?>" alt="<?php echo htmlspecialchars($rec['name']); ?>">
          <h3><?php echo htmlspecialchars($rec['name']); ?></h3>
          <p class="price">$<?php echo number_format($rec['price'], 2); ?></p>
        </div>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <!-- ── VIEW FULL MENU BUTTON ── -->
    <div class="menu-footer">
      <a href="menu.php" class="view-all">
        <i class="fa-solid fa-arrow-right"></i> View Full Menu
      </a>
    </div>
    
    <!-- ── SMALL DISCLAIMER ── -->
    <p style="text-align:center; color:#666666; font-size:13px; margin-top:16px;">
      Browse our full menu with over 50 unique drinks
    </p>

  </div>
</section>

<!-- ── CONTACT ── -->
<section id="contact">
  <h2>CONTACT US</h2>
  <p class="contact-subtitle">We'd love to hear from you</p>

  <div class="contact-wrap">
    <div class="contact-panel">
      <div class="contact-info">
        <div class="contact-info-item">
          <i class="fa-solid fa-location-dot"></i>
          <span>Phnom Penh, Cambodia</span>
        </div>
        <div class="contact-info-item">
          <i class="fa-solid fa-phone"></i>
          <span>+855 123 456 789</span>
        </div>
        <div class="contact-info-item">
          <i class="fa-solid fa-envelope"></i>
          <span>info@birdsnestcoffee.com</span>
        </div>
      </div>
      
      <div class="contact-social">
        <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
        <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
        <a href="#" aria-label="Twitter"><i class="fa-brands fa-twitter"></i></a>
        <a href="#" aria-label="TikTok"><i class="fa-brands fa-tiktok"></i></a>
      </div>
    </div>

    <div class="contact-panel">
      <form class="contact-form">
        <input type="email" placeholder="Email address" required>
        <textarea placeholder="Your message..." required></textarea>
        
        <div class="btn-wrapper">
          <button type="submit" class="btn-submit">Send Message</button>
        </div>
      </form>
    </div>
  </div>
</section>

<footer>
  <p>© 2025 <span>Bird's Nest Coffee</span> | Brewed with Love and Caffeine</p>
</footer>

<!-- ── JAVASCRIPT ── -->
<script>
// ── 1. SMOOTH SECTION REVEAL ──
const sections = document.querySelectorAll('section');

const revealObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('visible');
    }
  });
}, {
  threshold: 0.15,
  rootMargin: '0px 0px -50px 0px'
});

sections.forEach(section => {
  revealObserver.observe(section);
});

// ── 2. LIVE COUNTER ANIMATION ──
const stats = document.querySelectorAll('.stat-number');

const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const el = entry.target;
      const target = parseInt(el.getAttribute('data-target'));
      let current = 0;
      const increment = target / 80; // Speed control
      
      const updateCounter = () => {
        current += increment;
        if (current < target) {
          el.textContent = Math.ceil(current);
          requestAnimationFrame(updateCounter);
        } else {
          el.textContent = target;
        }
      };
      
      updateCounter();
      counterObserver.unobserve(el); // Stop observing after animation
    }
  });
}, { threshold: 0.5 });

stats.forEach(stat => {
  counterObserver.observe(stat);
});

// ── 3. PARALLAX HERO (Subtle scroll effect) ──
const hero = document.querySelector('.hero');
window.addEventListener('scroll', () => {
  const scrolled = window.scrollY;
  const speed = 0.5;
  if (hero) {
    hero.style.backgroundPositionY = `${scrolled * speed}px`;
  }
});
</script>

</body>
</html>