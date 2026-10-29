export const HERO_IMAGE_INTERVAL = 5000;

export function nextHeroImage(current: number, count: number) {
    return count > 0 ? (current + 1) % count : 0;
}
