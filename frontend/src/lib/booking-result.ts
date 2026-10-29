import type { Appointment } from "@/types";

let lastBooking: Appointment | null = null;

export const bookingResult = {
    get: () => lastBooking,
    set: (appointment: Appointment) => {
        lastBooking = appointment;
    },
    clear: () => {
        lastBooking = null;
    },
};
