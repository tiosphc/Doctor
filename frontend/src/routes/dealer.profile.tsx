import { createFileRoute } from "@tanstack/react-router";
import { DealerProfilePage } from "@/features/dealers/DealerProfilePage";

export const Route = createFileRoute("/dealer/profile")({ component: DealerProfilePage });
