import { createFileRoute } from "@tanstack/react-router";
import { DealerWalletTopUpResultPage } from "@/features/dealers/DealerWalletTopUpResultPage";

export const Route = createFileRoute("/dealer/top-up-result/$accountId/$topUpId")({
    component: DealerTopUpResultRoute,
});

function DealerTopUpResultRoute() {
    const { accountId, topUpId } = Route.useParams();

    return <DealerWalletTopUpResultPage accountId={Number(accountId)} topUpId={Number(topUpId)} />;
}
