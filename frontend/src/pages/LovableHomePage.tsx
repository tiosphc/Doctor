import { Nav } from "@/components/landing/Nav";
import { Hero } from "@/components/landing/Hero";
import { About, Services, Experience, Invitation, Footer } from "@/components/landing/Sections";
import { BookingJourney } from "@/components/landing/BookingJourney";
import { useScrollReveal } from "@/hooks/use-lux-motion";
import "@/components/landing/LandingTheme.css";
import "@/components/landing/Responsive.css";

export function LovableHomePage() {
    useScrollReveal();

    return (
        <div className="lovable-home">
            <Nav />
            <main>
                <Hero />
                <About />
                <Services />
                <Experience />
                <BookingJourney />
                <Invitation />
            </main>
            <Footer />
        </div>
    );
}
