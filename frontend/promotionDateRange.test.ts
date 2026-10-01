import { describe, expect, test } from "bun:test";
import {
    dateFromPromotionInput,
    formatPromotionDateTime,
    promotionDateInput,
    promotionRangeError,
    promotionTimeInput,
} from "./src/pages/admin/promotionDateRange";

describe("Promotion date range", () => {
    test("create maps selected local dates and times to the existing API fields", () => {
        const start = promotionDateInput(new Date(2026, 8, 1), "08:30");
        const end = promotionDateInput(new Date(2026, 9, 1), "21:45");

        expect(start).toBe("2026-09-01T08:30");
        expect(end).toBe("2026-10-01T21:45");
        expect(promotionRangeError(start, end)).toBeNull();
    });

    test("edit restores both dates and their existing times without shifting days", () => {
        const start = "2026-09-01T13:15";
        const end = "2026-10-01T18:40";

        expect(
            promotionDateInput(dateFromPromotionInput(start), promotionTimeInput(start, "00:00")),
        ).toBe(start);
        expect(
            promotionDateInput(dateFromPromotionInput(end), promotionTimeInput(end, "23:59")),
        ).toBe(end);
        expect(formatPromotionDateTime(start)).toBe("01/09/2026 13:15");
    });

    test("rejects an end before or equal to the start while allowing optional bounds", () => {
        expect(promotionRangeError("2026-10-01T10:00", "2026-10-01T09:00")).not.toBeNull();
        expect(promotionRangeError("2026-10-01T10:00", "2026-10-01T10:00")).not.toBeNull();
        expect(promotionRangeError("2026-10-01T10:00", null)).toBeNull();
        expect(promotionRangeError(null, null)).toBeNull();
    });
});
