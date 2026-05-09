<!-- footer.php -->
<style>
.custom-footer {
    /* Subtle gradient background for a modern look */
    background: linear-gradient(to bottom, #ffffff, #f8f9fa);
    color: #495057;
    border-top: 3px solid #1e3a8a; /* Deep Navy Bank Blue top border */
    padding-top: 30px;
    padding-bottom: 20px;
    margin-top: 50px;
}

.footer-name {
    color: #1e3a8a; /* Bank Blue */
    font-size: 1.1rem;
    letter-spacing: 0.5px;
}

.footer-division {
    color: #059669; /* Professional Emerald Green for 'Investment' */
    font-weight: 600;
}

.footer-bank {
    color: #334155;
    font-weight: 500;
}

.footer-hr {
    width: 60px;
    height: 3px;
    background-color: #fbbf24; /* Gold accent line */
    margin: 10px auto 15px auto;
    border: none;
    opacity: 1;
}

.footer-address {
    color: #94a3b8;
    font-style: italic;
}
</style>

<footer class="custom-footer">
    <div class="container text-center">
        <!-- Colorful Name -->
        <p class="mb-1 fw-bold footer-name">Zahedul Alam Chowdhury</p>
        
        <!-- Gold Accent Divider -->
        <hr class="footer-hr">
        
        <!-- Division with Green Accent -->
        <p class="mb-1 small">
            Officer | <span class="footer-division">Investment Division</span>
        </p>
        
        <!-- Bank Name -->
        <p class="mb-1 small footer-bank">First Security Islami Bank PLC</p>
        
        <!-- Address in Muted Gray -->
        <p class="footer-address" style="font-size: 0.75rem;">
            <i class="fas fa-map-marker-alt me-1"></i> Head Office, Gulshan, Dhaka
        </p>
    </div>
</footer>