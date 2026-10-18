            <div class="footer-section footer-links">
                <a href="https://www.wordofgodteam.com/" target="_blank" class="footer-link">
                    <i class="bi bi-people me-1"></i>About Us
                </a>
                <span class="footer-separator">|</span>
                <a href="https://wordofgod.in/bible-devotions/" target="_blank" class="footer-link">
                    <i class="bi bi-journal-bookmark me-1"></i>Bible Devotions
                </a>
                <span class="footer-separator">|</span>
                <a href="https://wordofgod.in/bibles/" target="_blank" class="footer-link">
                    <i class="bi bi-book-half me-1"></i>Online Bibles
                </a>
                <span class="footer-separator">|</span>
                <a href="https://wordofgod.in/good-news-collections/" target="_blank" class="footer-link">
                    <i class="bi bi-box-seam me-1"></i>Good News Collections
                </a>
                <span class="footer-separator">|</span>
                <a href="https://wordofgod.in/bibledictionary/" target="_blank" class="footer-link">
                    <i class="bi bi-collection me-1"></i>Bible Dictionaries
                </a>
                <span class="footer-separator">|</span>
                <a href="https://wordofgod.in/bible-concordance/" target="_blank" class="footer-link">
                    <i class="bi bi-search me-1"></i>Bible Concordances
                </a>
                <span class="footer-separator">|</span>
                <a href="https://wordofgod.in/bible-wallpapers/" target="_blank" class="footer-link">
                    <i class="bi bi-card-image me-1"></i>Bible Wallpapers
                </a>
                <span class="footer-separator">|</span>
                <a href="https://wordofgod.in/bible-app-modules/" target="_blank" class="footer-link">
                    <i class="bi bi-phone me-1"></i>Bible App Modules
                </a>
                <span class="footer-separator">|</span>
                <a href="https://wordofgod.in/" target="_blank" class="footer-link">
                    <i class="bi bi-gift me-1"></i>Free Christian Resources
                </a>
                <span class="footer-separator">|</span>
                <span class="footer-visitors">
                    <i class="bi bi-emoji-heart-eyes me-1"></i>Visitors: <?= $visitors2 ?>
                </span>
                <span class="footer-separator">|</span>
                <span class="footer-visitors">
                    <i class="bi bi-share me-1"></i>Shares: <?= $shares2 ?? 0 ?>
                </span>
                <span class="footer-separator">|</span>
                <span class="footer-visitors">
                    <i class="bi bi-code-slash me-1"></i>Embeds: <?= $embeds2 ?? 0 ?>
                </span>
                <span class="footer-separator">|</span>
                <span class="footer-visitors">
                    <i class="bi bi-download me-1"></i>Downloads: <?= $downloads2 ?? 0 ?>
                </span>
                <span class="footer-separator">|</span>
                <span class="footer-visitors">
                    <i class="bi bi-check2-circle me-1"></i>Quizzes Completed: <?= number_format((int) ($quizzesCompletedTotal ?? 0)) ?>
                </span>
                <span class="footer-separator">|</span>
                <span class="footer-visitors">
                    <i class="bi bi-patch-question me-1"></i>Questions Answered: <?= number_format((int) ($questionsAnsweredTotal ?? 0)) ?>
                </span>
                <?php if (!empty($topBooks)): ?>
                    <span class="footer-separator">|</span>
                    <span class="footer-visitors">
                        <i class="bi bi-bookmark-star me-1"></i>Top 3 Books: <?= htmlspecialchars(implode(', ', $topBooks)) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($topLanguage)): ?>
                    <span class="footer-separator">|</span>
                    <span class="footer-visitors">
                        <i class="bi bi-translate me-1"></i>Top Language: <?= htmlspecialchars((string) $topLanguage) ?>
                    </span>
                <?php endif; ?>
            </div>

            <div style="position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; opacity: 0; pointer-events: none;" aria-hidden="true">
                <a href="bot.php" tabindex="-1">.</a>
            </div>