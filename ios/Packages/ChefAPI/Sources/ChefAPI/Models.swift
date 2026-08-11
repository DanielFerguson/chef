import Foundation

public struct UserSummary: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let name: String
    public let email: String
}

public struct PersonSummary: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let name: String
}

public struct HouseholdSummary: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let name: String
    public let timezone: String
    public let people: [PersonSummary]
}

public struct MealPlanSummary: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let title: String
    public let startsOn: String
    public let endsOn: String
    public let revision: Int
    public let planningConfirmedAt: String?
}

public struct NotificationFeed: Codable, Hashable, Sendable {
    public let unreadCount: Int
    public let items: [ChefNotification]
}

public struct ChefNotification: Codable, Hashable, Sendable, Identifiable {
    public let id: String
    public let title: String
    public let message: String
    public let status: String
    public let basketRunId: Int
    public let readAt: String?
    public let createdAt: String?
}

public struct SessionResponse: Codable, Hashable, Sendable {
    public let user: UserSummary
    public let household: HouseholdSummary
    public let plans: [MealPlanSummary]
    public let notifications: NotificationFeed
}

public struct TokenResponse: Codable, Hashable, Sendable {
    public let token: String
    public let expiresAt: String
    public let user: UserSummary
}

public struct TokenRevocationResponse: Codable, Hashable, Sendable {
    public let revoked: Bool
}

public struct AuthenticationRequest: Codable, Hashable, Sendable {
    public let email: String
    public let password: String
    public let deviceName: String
    public let code: String?
    public let recoveryCode: String?

    public init(
        email: String,
        password: String,
        deviceName: String,
        code: String? = nil,
        recoveryCode: String? = nil
    ) {
        self.email = email
        self.password = password
        self.deviceName = deviceName
        self.code = code
        self.recoveryCode = recoveryCode
    }
}

public struct WorkspaceResponse: Codable, Hashable, Sendable {
    public let workspace: MealPlanWorkspace
}

public struct MealPlanWorkspace: Codable, Hashable, Sendable {
    public let plan: MealPlanSummary
    public let approvalBrief: MealPlanApprovalBrief?
    public let groceryPreparation: GroceryPreparation
}

public struct MealPlanApprovalBrief: Codable, Hashable, Sendable {
    public let planId: Int
    public let planRevision: Int
    public let mealCount: Int
    public let meals: [MealPlanApprovalMeal]
    public let estimatedMinutes: Int
    public let estimatedCostCents: Int
    public let safety: MealPlanApprovalSafety
    public let purchasePolicy: MealPlanApprovalPurchasePolicy
    public let groceryPreparation: MealPlanApprovalGroceryPreparation
}

public struct MealPlanApprovalMeal: Codable, Hashable, Sendable, Identifiable {
    public var id: Int { mealSlotId }

    public let mealSlotId: Int
    public let date: String
    public let kind: String
    public let label: String?
    public let title: String?
    public let summary: String?
    public let estimatedMinutes: Int?
    public let estimatedCostCents: Int?
    public let isReplacement: Bool
    public let hasConflictingProposals: Bool
    public let participants: [MealPlanApprovalParticipant]
    public let totalServings: FlexibleNumber
    public let participantDefault: MealPlanParticipantDefault
}

public struct MealPlanApprovalParticipant: Codable, Hashable, Sendable, Identifiable {
    public var id: Int { personId }

    public let personId: Int
    public let name: String
    public let servings: FlexibleNumber
}

public struct MealPlanParticipantDefault: Codable, Hashable, Sendable {
    public let origin: String
    public let provisional: Bool
    public let sourceMealSlotId: Int?
    public let sourceLabel: String?
}

public struct MealPlanApprovalSafety: Codable, Hashable, Sendable {
    public let constraints: [MealPlanApprovalConstraint]
    public let inferred: Bool
}

public struct MealPlanApprovalConstraint: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let kind: String
    public let subject: String
    public let details: String?
    public let severity: String?
    public let person: String?
    public let explicitlyConfirmedAt: String?
}

public struct MealPlanApprovalPurchasePolicy: Codable, Hashable, Sendable {
    public let provider: String
    public let homeBrandPreference: String
    public let bulkPreference: String
    public let organicPreference: String
    public let preferredBrands: [String]
    public let basketTargetCents: Int?
    public let basketTargetSource: String
}

public struct MealPlanApprovalGroceryPreparation: Codable, Hashable, Sendable {
    public let provider: String
    public let hasStandingConsent: Bool
    public let approvalWillReplaceBasket: Bool
    public let effect: String
}

public struct GroceryPreparation: Codable, Hashable, Sendable {
    public let enabled: Bool
    public let provider: String?
    public let approvalLabel: String?
    public let hasStandingConsent: Bool?
    public let connection: RetailerConnectionSummary?
    public let canConnect: Bool?
    public let run: GroceryPreparationRun?
    public let consent: ConsentDisclosure?
}

public struct RetailerConnectionSummary: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let status: String
    public let ownedByCurrentUser: Bool?
}

public struct GroceryPreparationRun: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let status: String
    public let publicState: String?
    public let publicOutcome: String?
    public let attentionKind: String?
    public let failureMessage: String?
    public let chefSubtotalCents: Int?
    public let retailerTotalCents: Int?
    public let capturedAt: String?
    public let polling: Bool
}

public struct ConsentDisclosure: Codable, Hashable, Sendable {
    public let version: String
    public let disclosure: String
    public let links: ConsentLinks
}

public struct ConsentLinks: Codable, Hashable, Sendable {
    public let colesOnlineSafety: String?
    public let colesCustomerAgreement: String?
}

public struct ConnectionStartResponse: Codable, Hashable, Sendable {
    public let connection: ConnectionMutation
    public let session: LiveSession
    public let consent: ConsentDisclosure
}

public struct ConnectionResponse: Codable, Hashable, Sendable {
    public let connection: ConnectionMutation
}

public struct ConnectionMutation: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let status: String?
    public let standingConsent: Bool?
    public let lastVerifiedAt: String?
}

public struct LiveSession: Codable, Hashable, Sendable {
    public let liveViewUrl: URL
    public let expiresAt: String
}

public struct StandingConsentRequest: Codable, Hashable, Sendable {
    public let standingConsent: Bool
    public let disclosureVersion: String

    public init(disclosureVersion: String) {
        standingConsent = true
        self.disclosureVersion = disclosureVersion
    }
}

public enum LiveInput: Hashable, Sendable {
    case text(String)
    case key(String)
}

extension LiveInput: Encodable {
    enum CodingKeys: String, CodingKey {
        case text
        case key
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)

        switch self {
        case let .text(value):
            try container.encode(value, forKey: .text)
        case let .key(value):
            try container.encode(value, forKey: .key)
        }
    }
}

public struct LiveInputResponse: Codable, Hashable, Sendable {
    public let forwarded: Bool
    public let kind: String
}

public struct LiveSessionReleaseResponse: Codable, Hashable, Sendable {
    public let released: Bool
}

public struct BasketEnvelope: Codable, Hashable, Sendable {
    public let basket: BasketView
}

public struct BasketMutationResponse: Codable, Hashable, Sendable {
    public let basketRun: BasketRunMutation
}

public struct RetailerPurchasePolicyEnvelope: Codable, Hashable, Sendable {
    public let policy: RetailerPurchasePolicyView
    public let productPreferences: [RetailerProductPreferenceView]
    public let can: RetailerPurchasePolicyCapabilities
}

public struct RetailerPurchasePolicyView: Codable, Hashable, Sendable {
    public let provider: String
    public let homeBrandPreference: String
    public let bulkPreference: String
    public let organicPreference: String
    public let preferredBrands: [String]
    public let defaultBasketTargetCents: Int?
    public let fingerprint: String
}

public struct RetailerProductPreferenceView: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let ingredient: String
    public let form: String?
    public let sku: String
    public let productTitle: String
    public let createdAt: String
}

public struct RetailerPurchasePolicyCapabilities: Codable, Hashable, Sendable {
    public let update: Bool
}

public struct RetailerPurchasePolicyRequest: Codable, Hashable, Sendable {
    public let homeBrandPreference: String
    public let bulkPreference: String
    public let organicPreference: String
    public let preferredBrands: [String]
    public let defaultBasketTargetCents: Int?

    public init(
        homeBrandPreference: String,
        bulkPreference: String,
        organicPreference: String,
        preferredBrands: [String],
        defaultBasketTargetCents: Int?
    ) {
        self.homeBrandPreference = homeBrandPreference
        self.bulkPreference = bulkPreference
        self.organicPreference = organicPreference
        self.preferredBrands = preferredBrands
        self.defaultBasketTargetCents = defaultBasketTargetCents
    }
}

public struct MealPlanPurchasePreferenceResponse: Codable, Hashable, Sendable {
    public let purchasePreference: MealPlanPurchasePreferenceView
}

public struct MealPlanPurchasePreferenceView: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let provider: String
    public let basketTargetCents: Int?
}

public struct RetailerProductPreferenceResponse: Codable, Hashable, Sendable {
    public let preference: RetailerProductPreferenceMutation
}

public struct RetailerProductPreferenceMutation: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let sku: String?
    public let productTitle: String?
    public let revoked: Bool
}

public struct BasketBudgetOverrideResponse: Codable, Hashable, Sendable {
    public let basketRun: BasketBudgetOverrideMutation
}

public struct BasketBudgetOverrideMutation: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let status: String
    public let budgetOverrideCents: Int
    public let budgetOverriddenAt: String
}

public struct BasketRunMutation: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let status: String
}

public struct ReviewSessionResponse: Codable, Hashable, Sendable {
    public let session: LiveSession
}

public struct NotificationReadResponse: Codable, Hashable, Sendable {
    public let readAt: String?
}

public struct BasketView: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let status: String
    public let statusLabel: String
    public let publicState: String?
    public let publicOutcome: String?
    public let confirmed: Bool
    public let uncertain: Bool
    public let failureCode: String?
    public let failureMessage: String?
    public let attention: BasketAttention?
    public let plan: BasketPlan
    public let connection: BasketConnection?
    public let groceryPlan: BasketGroceryPlan?
    public let effectivePolicy: BasketEffectivePolicy?
    public let adjustment: BasketAdjustment?
    public let requirements: [BasketRequirement]
    public let items: [BasketItem]
    public let totals: BasketTotals
    public let replacedLineCount: Int
    public let previousLineCount: Int
    public let capturedAt: String?
    public let can: BasketCapabilities
}

public struct BasketEffectivePolicy: Codable, Hashable, Sendable {
    public let provider: String
    public let homeBrandPreference: String
    public let bulkPreference: String
    public let organicPreference: String
    public let preferredBrands: [String]
    public let basketTargetCents: Int?
    public let fingerprint: String?
}

public struct BasketAdjustment: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let kind: String
    public let status: String
    public let generatedAt: String
    public let items: [BasketAdjustmentItem]
}

public struct BasketAdjustmentItem: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let mealSlotId: Int
    public let date: String?
    public let kind: String?
    public let previousTitle: String?
    public let replacementTitle: String
    public let replacementSummary: String?
    public let estimatedMinutes: Int?
    public let estimatedCostCents: Int?
    public let proposalId: Int
    public let proposalStatus: String?
}

public struct BasketAttention: Codable, Hashable, Sendable {
    public let kind: String
    public let budgetTargetCents: Int?
    public let selectedSubtotalCents: Int?
    public let blockedRequirementCount: Int?
}

public struct BasketPlan: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let title: String
    public let startsOn: String
    public let endsOn: String
}

public struct BasketConnection: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let provider: String
    public let status: String
    public let ownedByCurrentUser: Bool
    public let lastVerifiedAt: String?
}

public struct BasketGroceryPlan: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let version: Int
    public let status: String
    public let builtAt: String?
}

public struct BasketRequirement: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let name: String
    public let status: String
    public let quantity: FlexibleNumber?
    public let unit: String?
    public let quantityUnknown: Bool
}

public struct BasketItem: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let requirement: BasketItemRequirement
    public let product: BasketProduct
    public let reasoning: String
    public let lowConfidence: Bool
    public let semanticTier: Int?
    public let policyDecisions: [String]?
    public let policyExceptions: [String]?
    public let alternatives: [BasketItemAlternative]?
    public let canPrefer: Bool?
    public let verifiedAt: String?
    public let sources: [BasketItemSource]
}

public struct BasketItemAlternative: Codable, Hashable, Sendable, Identifiable {
    public var id: Int { candidateId }

    public let candidateId: Int
    public let sku: String
    public let title: String
    public let brand: String?
    public let packQuantity: FlexibleNumber?
    public let packUnit: String?
    public let packCount: Int
    public let capturedPriceCents: Int
    public let totalPriceCents: Int
    public let capturedAt: String
    public let policyComparison: String
}

public struct BasketItemRequirement: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let name: String
    public let quantity: FlexibleNumber?
    public let unit: String?
    public let quantityUnknown: Bool
}

public struct BasketProduct: Codable, Hashable, Sendable {
    public let sku: String
    public let title: String
    public let brand: String?
    public let packQuantity: FlexibleNumber?
    public let packUnit: String?
    public let absoluteQuantity: Int
    public let unitPriceCents: Int
    public let linePriceCents: Int
}

public struct BasketItemSource: Codable, Hashable, Sendable {
    public let plannedMealId: Int
    public let mealTitle: String
    public let recipeVersionId: Int
    public let recipeTitle: String
    public let recipeVersion: Int
    public let ingredientName: String
    public let scaledQuantity: FlexibleNumber?
    public let unit: String?
}

public struct BasketTotals: Codable, Hashable, Sendable {
    public let chefSubtotalCents: Int?
    public let retailerTotalCents: Int?
    public let priceNotice: String
}

public struct BasketCapabilities: Codable, Hashable, Sendable {
    public let retry: Bool
    public let restore: Bool
    public let review: Bool
    public let overrideBudget: Bool?
}

public struct FlexibleNumber: Codable, Hashable, Sendable {
    public let value: Double

    public init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()

        if let number = try? container.decode(Double.self) {
            value = number
            return
        }

        let string = try container.decode(String.self)
        guard let number = Double(string) else {
            throw DecodingError.dataCorruptedError(
                in: container,
                debugDescription: "Expected a numeric value."
            )
        }

        value = number
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(value)
    }
}
