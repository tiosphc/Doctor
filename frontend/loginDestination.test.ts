import { describe, expect, test } from "bun:test";
import { loginDestination } from "./src/lib/loginDestination";

describe("login destination", () => {
    test("sends each staff role to its own React portal", () => {
        expect(loginDestination("admin", false, "/checkout")).toBe("/admin");
        expect(loginDestination("doctor", false, null)).toBe("/doctor");
        expect(loginDestination("receptionist", false, null)).toBe("/receptionist");
    });

    test("sends an approved dealer to the Dealer Portal", () => {
        expect(loginDestination("customer", true, null)).toBe("/dealer");
        expect(loginDestination("customer", false, null)).toBe("/account");
        expect(loginDestination("customer", true, "/dealer/orders/12")).toBe("/dealer/orders/12");
        expect(loginDestination("customer", false, "/dealer/orders/12")).toBe("/account");
    });

    test("keeps safe retail return paths and rejects external targets", () => {
        expect(loginDestination("customer", false, "/checkout")).toBe("/checkout");
        expect(loginDestination("customer", false, "/my-orders/12")).toBe("/my-orders/12");
        expect(loginDestination("customer", false, "https://evil.example/checkout")).toBe(
            "/account",
        );
        expect(loginDestination("customer", false, "//evil.example/checkout")).toBe("/account");
        expect(loginDestination("customer", false, "/checkout#frag")).toBe("/account");
    });
});
