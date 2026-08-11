import Foundation

struct GroceryStatusPresentation: Equatable, Sendable {
    let title: String
    let detail: String
    let isActive: Bool
    let needsAttention: Bool

    static func make(status: String) -> Self {
        switch status {
        case "waiting_for_recipes":
            Self(title: "Preparing recipes", detail: "Chef is drafting every recipe in one batch.", isActive: true, needsAttention: false)
        case "waiting_for_connection":
            Self(title: "Connect Coles", detail: "The Coles account owner needs to sign in once.", isActive: false, needsAttention: true)
        case "building_requirements":
            Self(title: "Building grocery requirements", detail: "Chef is scaling and combining recipe ingredients.", isActive: true, needsAttention: false)
        case "discovering_products":
            Self(title: "Finding Coles products", detail: "Chef is checking available products and labels.", isActive: true, needsAttention: false)
        case "selecting_products":
            Self(title: "Choosing suitable products", detail: "Only products that passed the hard checks are considered.", isActive: true, needsAttention: false)
        case "preparing_resolution":
            Self(title: "Preparing one plan resolution", detail: "Chef is drafting one coherent alternative for you to review.", isActive: true, needsAttention: false)
        case "needs_plan_review":
            Self(title: "Revised plan needs review", detail: "Review the complete plan change and approve it once.", isActive: false, needsAttention: true)
        case "revalidating_products":
            Self(title: "Checking stock and prices", detail: "Chef is rechecking every selection before the basket changes.", isActive: true, needsAttention: false)
        case "products_selected":
            Self(title: "Products selected; basket unchanged", detail: "This rollout is observing product choices without changing Coles.", isActive: false, needsAttention: false)
        case "replacing_basket":
            Self(title: "Replacing the Coles basket", detail: "The previous basket has been captured for restoration.", isActive: true, needsAttention: false)
        case "ready":
            Self(title: "Basket ready", detail: "Chef verified the products and quantities in Coles.", isActive: false, needsAttention: false)
        case "needs_product":
            Self(title: "Needs a product", detail: "No available product passed every required check.", isActive: false, needsAttention: true)
        case "reauthentication_required":
            Self(title: "Reconnect Coles", detail: "The Coles account owner needs to sign in again.", isActive: false, needsAttention: true)
        case "uncertain", "needs_attention":
            Self(title: "Basket needs review", detail: "Chef stopped because it could not confirm the basket state.", isActive: false, needsAttention: true)
        case "restoring":
            Self(title: "Restoring the previous basket", detail: "Chef is verifying each restored line.", isActive: true, needsAttention: false)
        case "restored":
            Self(title: "Previous basket restored", detail: "Chef verified the restoration.", isActive: false, needsAttention: false)
        case "failed":
            Self(title: "Preparation stopped", detail: "Chef stopped before it could verify a safe result.", isActive: false, needsAttention: true)
        default:
            Self(title: "Basket preparation", detail: "Open the basket for the latest state.", isActive: false, needsAttention: false)
        }
    }
}
