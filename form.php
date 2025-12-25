<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driving Experience Recorder</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=BBH+Bogle&family=BBH+Hegarty&family=Lilita+One&family=Stack+Sans+Text:wght@200..700&family=Chivo:wght@400;600&display=swap"
        rel="stylesheet">
    <style type="text/tailwindcss">
        @theme {
            --color-main-green: #1E3C14;
            --color-hover-green: #EEF3CB;
            --color-yellow: #FFF7C8;
        }

        .chivo-regular {
            font-family: "Chivo", sans-serif;
            font-optical-sizing: auto;
            font-weight: 800;
            font-style: normal;
        }

        .bbh-hegarty-regular {
            font-family: "BBH Hegarty", sans-serif;
            font-weight: 400;
            font-style: normal;
        }

        @keyframes marquee {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
        }
        
        @keyframes marqueeY {
            from { transform: translateY(0); }
            to { transform: translateY(-50%); }
        }

        /* Mobile menu toggle */
        .mobile-menu {
            display: none;
        }

        .mobile-menu.active {
            display: block;
        }
    </style>
</head>

<body class="bg-yellow">
    <header class="bg-[#FFF7EE] fixed w-full border-b border-main-green shadow-md z-50">
        <nav class="max-w-[1680px] mx-auto px-3 sm:px-6">
            <div class="flex justify-between items-center py-3 sm:py-4 lg:h-30">
                <h1 class="text-base sm:text-lg md:text-xl lg:text-2xl font-bold uppercase text-main-green bbh-hegarty-regular tracking-wider leading-tight">
                    Driving <br>Experience<br> Recorder
                </h1>
                
                <!-- Mobile menu button -->
                <button id="mobile-menu-btn" class="lg:hidden text-main-green p-2 hover:bg-hover-green rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>

                <!-- Desktop menu -->
                <ul class="hidden lg:flex  xl:text-base 2xl:text-xl chivo-regular h-full text-main-green">
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="form.php" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Home</a>
                    </li>
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="record.php" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Record</a>
                    </li>
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="experiences.php" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Experiences</a>
                    </li>
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="summary.php" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Summary</a>
                    </li>
                </ul>
            </div>

            <!-- Mobile menu -->
            <div id="mobile-menu" class="mobile-menu lg:hidden pb-3">
                <ul class="flex flex-col text-sm sm:text-base chivo-regular text-main-green space-y-1">
                    <li class="hover:bg-hover-green rounded-lg transition-colors">
                        <a href="form.php" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">Home</a>
                    </li>
                    <li class="hover:bg-hover-green rounded-lg transition-colors">
                        <a href="record.php" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">Record Experience</a>
                    </li>
                    <li class="hover:bg-hover-green rounded-lg transition-colors">
                        <a href="experiences.php" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">View Experiences</a>
                    </li>
                    <li class="hover:bg-hover-green rounded-lg transition-colors">
                        <a href="summary.php" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">Summary</a>
                    </li>
                </ul>
            </div>
        </nav>
    </header>

    <main class="bg-yellow flex items-center min-h-screen pt-20 sm:pt-24 lg:pt-32 pb-8 sm:pb-12 lg:pb-16">
        <div class="w-full max-w-[1400px] mx-auto px-3 sm:px-6 lg:px-8 flex flex-col justify-center items-center">
            <h2 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl bbh-hegarty-regular text-center mb-4 sm:mb-6 lg:mb-8 text-main-green font-bold px-2">
                Driving Experience Insight
            </h2>
            <p class="text-sm sm:text-base md:text-lg lg:text-xl text-center chivo-regular tracking-wide lg:tracking-wider text-main-green max-w-xs sm:max-w-2xl md:max-w-3xl lg:max-w-4xl mb-6 sm:mb-10 lg:mb-12 leading-relaxed px-3 sm:px-4">
                Understand every journey better. This platform lets you record and analyze driving conditions — from road surface and weather to traffic, visibility, maneuvers, and time of day — using clear, visual inputs that make reporting fast and intuitive. Whether for learning, research, or safety analysis, we turn real driving experiences into structured, meaningful data.
            </p>

            <section class="w-full overflow-hidden">
                <div id="marquee" class="w-full relative mx-auto my-6 sm:my-10 lg:my-16">
                    <!-- Left Gradient -->
                    <div class="absolute left-0 top-0 h-full w-8 sm:w-16 lg:w-20 z-10 pointer-events-none bg-gradient-to-r from-yellow to-transparent"></div>

                    <!-- Marquee Container -->
                    <div class="overflow-hidden">
                        <div id="marqueeInner" class="flex animate-[marquee_linear_infinite]">
                            <div id="cards" class="flex gap-4 sm:gap-6"></div>
                        </div>
                    </div>

                    <!-- Right Gradient -->
                    <div class="absolute right-0 top-0 h-full w-8 sm:w-16 lg:w-20 z-10 pointer-events-none bg-gradient-to-l from-yellow to-transparent"></div>
                </div>
            </section>
        </div>
    </main>

    <footer class="bg-main-green text-yellow chivo-regular text-center p-4 sm:p-6 text-sm sm:text-base">
        &copy; 2025 Driving Experience Recorder. All rights reserved.
    </footer>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');

        mobileMenuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('active');
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!mobileMenuBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                mobileMenu.classList.remove('active');
            }
        });

        // Card data
        const cardData = [
            {
                title: "Road Surface Types",
                image: "./assets/road.jpeg",
            },
            {
                title: "Weather Conditions",
                image: "./assets/weather.jpeg",
            },
            {
                title: "Traffic Density",
                image: "./assets/traffic.jpeg",
            },
            {
                title: "Parking Type",
                image: "./assets/parking.jpeg",
            },
            {
                title: "Maneuvers Executed",
                image: "./assets/manouvres.jpeg",
            },
            {
                title: "Visibility Conditions",
                image: "./assets/visibility.jpeg",
            },
        ];

        const cardsContainer = document.getElementById("cards");
        const marqueeInner = document.getElementById("marqueeInner");
        const marquee = document.getElementById("marquee");

        function renderCards() {
            cardsContainer.innerHTML = '';
            const isMobile = window.innerWidth < 640;
            const isTablet = window.innerWidth >= 640 && window.innerWidth < 1024;
            
            // Always duplicate cards for infinite scroll
            const cardsToRender = [...cardData, ...cardData];
            
            cardsToRender.forEach(card => {
                const cardEl = document.createElement("div");
                
                // Responsive card classes
                if (isMobile) {
                    cardEl.className = "w-[280px] h-[200px] relative group transition-all rounded-xl duration-300 hover:scale-95 flex-shrink-0";
                } else if (isTablet) {
                    cardEl.className = "w-[320px] h-[220px] relative group transition-all rounded-xl duration-300 hover:scale-95 flex-shrink-0";
                } else {
                    cardEl.className = "w-[340px] h-[240px] relative group transition-all rounded-xl duration-300 hover:scale-90 flex-shrink-0";
                }

                cardEl.innerHTML = `
                    <img src="${card.image}" alt="${card.title}" class="w-full h-full object-cover rounded-xl" />
                    <div class="absolute inset-0 flex items-center justify-center px-4 opacity-0 group-hover:opacity-100 transition-all duration-300 backdrop-blur-md bg-black/30 rounded-xl">
                        <p class="text-white text-base sm:text-lg lg:text-xl font-semibold text-center">
                            ${card.title}
                        </p>
                    </div>
                `;

                cardsContainer.appendChild(cardEl);
            });

            // Set animation duration based on screen size
            let duration;
            if (isMobile) {
                duration = cardData.length * 3500; // Slower on mobile
            } else if (isTablet) {
                duration = cardData.length * 3000;
            } else {
                duration = cardData.length * 2500;
            }
            marqueeInner.style.animationDuration = duration + "ms";
        }

        // Initial render
        renderCards();

        // Pause animation on hover
        marquee.addEventListener("mouseenter", () => {
            marqueeInner.style.animationPlayState = "paused";
        });

        marquee.addEventListener("mouseleave", () => {
            marqueeInner.style.animationPlayState = "running";
        });

        // Re-render on resize
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                renderCards();
            }, 250);
        });
    </script>
</body>

</html>
