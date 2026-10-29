import { createFileRoute } from "@tanstack/react-router";
import { DealerApplyPage } from "@/features/dealer-applications/DealerApplyPage";

export const Route = createFileRoute("/dealer/apply")({ component: DealerApplyPage });
