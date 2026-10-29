import { createFileRoute } from "@tanstack/react-router";
import { DealerTopUpPage } from "@/features/dealers/DealerTopUpPage";

export const Route = createFileRoute("/dealer/top-up")({ component: DealerTopUpPage });
