import ChefAPI
import SwiftUI

struct BasketScreen: View {
    let runID: Int

    @EnvironmentObject private var appState: AppState
    @State private var confirmsRestoration = false
    @State private var showsReview = false

    var body: some View {
        Group {
            if let basket = matchingBasket {
                List {
                    Section {
                        BasketStatusHeader(basket: basket)
                    }

                    if basket.uncertain, let message = basket.failureMessage {
                        Section("Needs attention") {
                            Label(message, systemImage: "exclamationmark.triangle")
                                .foregroundStyle(.orange)
                        }
                    }

                    if basket.publicState == "plan_review_required" {
                        BasketRecoverySection(basket: basket)
                    }

                    if let policy = basket.effectivePolicy {
                        Section("Applied grocery policy") {
                            LabeledContent("Home brand", value: policy.homeBrandPreference.capitalized)
                            LabeledContent("Bulk packs", value: policy.bulkPreference.capitalized)
                            if let target = policy.basketTargetCents {
                                LabeledContent("Basket target", value: target.currencyAUD)
                            }
                        }
                    }

                    Section("Selected products") {
                        ForEach(basket.items) { item in
                            BasketItemRow(item: item)
                        }
                    }

                    Section("Totals") {
                        if let subtotal = basket.totals.chefSubtotalCents {
                            LabeledContent("Chef items", value: subtotal.currencyAUD)
                        }
                        if let total = basket.totals.retailerTotalCents {
                            LabeledContent("Full Coles basket", value: total.currencyAUD)
                        }
                        Text(basket.totals.priceNotice)
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }

                    Section("Replacement") {
                        LabeledContent(
                            "Lines replaced",
                            value: "\(basket.replacedLineCount)"
                        )
                        LabeledContent(
                            "Previous basket lines",
                            value: "\(basket.previousLineCount)"
                        )
                        if let capturedAt = basket.capturedAt {
                            LabeledContent("Captured", value: capturedAt)
                        }
                    }

                    Section {
                        if basket.can.retry {
                            Button("Retry preparation", systemImage: "arrow.clockwise") {
                                Task {
                                    await appState.retryBasket()
                                }
                            }
                            .disabled(appState.isWorking)
                        }

                        if basket.can.restore {
                            Button(
                                "Restore previous basket",
                                systemImage: "arrow.uturn.backward",
                                role: .destructive
                            ) {
                                confirmsRestoration = true
                            }
                            .disabled(appState.isWorking)
                        }

                        if basket.can.review {
                            Button("Review in Coles", systemImage: "safari") {
                                Task {
                                    await appState.startBasketReview()
                                    showsReview = appState.liveSessionPurpose == .review
                                }
                            }
                            .disabled(appState.isWorking)
                        }
                    } footer: {
                        Text("Review is owner-only and pauses all automation. Checkout and payment remain entirely in Coles.")
                    }
                }
                .refreshable {
                    await appState.openBasket(runID: runID)
                }
            } else {
                ProgressView("Loading basket")
            }
        }
        .navigationTitle("Coles basket")
        .navigationBarTitleDisplayMode(.inline)
        .task(id: runID) {
            await appState.openBasket(runID: runID)
        }
        .confirmationDialog(
            "Replace the current basket with the captured previous basket?",
            isPresented: $confirmsRestoration,
            titleVisibility: .visible
        ) {
            Button("Restore previous basket", role: .destructive) {
                Task {
                    await appState.restoreBasket()
                }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Chef will stop if it cannot verify the restoration.")
        }
        .sheet(isPresented: $showsReview, onDismiss: {
            if appState.liveSessionPurpose == .review {
                Task {
                    await appState.releaseLiveView()
                }
            }
        }) {
            BasketReviewView()
                .environmentObject(appState)
        }
    }

    private var matchingBasket: BasketView? {
        appState.basket?.id == runID ? appState.basket : nil
    }
}

private struct BasketStatusHeader: View {
    let basket: BasketView

    var body: some View {
        let presentation = GroceryStatusPresentation.make(status: basket.status)

        VStack(alignment: .leading, spacing: 8) {
            HStack {
                if presentation.isActive {
                    ProgressView()
                } else {
                    Image(systemName: basket.confirmed
                        ? "checkmark.seal.fill"
                        : (basket.uncertain ? "exclamationmark.triangle.fill" : "circle"))
                        .foregroundStyle(basket.confirmed ? .green : (basket.uncertain ? .orange : .secondary))
                }

                Text(presentation.title)
                    .font(.title3.weight(.semibold))
            }

            Text(presentation.detail)
                .foregroundStyle(.secondary)

            if basket.confirmed {
                Text("Confirmed from the actual Coles basket")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(.green)
            } else if basket.uncertain {
                Text("Not confirmed")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(.orange)
            }
        }
        .padding(.vertical, 5)
    }
}

private struct BasketItemRow: View {
    let item: BasketItem
    @EnvironmentObject private var appState: AppState
    @State private var showsSources = false
    @State private var showsReasoning = false
    @State private var selectedAlternative: BasketItemAlternative?
    @State private var preferredCandidateId: Int?

    var body: some View {
        VStack(alignment: .leading, spacing: 7) {
            HStack(alignment: .firstTextBaseline) {
                Text(item.product.title)
                    .font(.headline)
                Spacer()
                Text(item.product.linePriceCents.currencyAUD)
                    .font(.subheadline.weight(.semibold))
            }

            Text("Quantity \(item.product.absoluteQuantity) · \(item.requirement.name)")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            DisclosureGroup("Why Chef chose this", isExpanded: $showsReasoning) {
                VStack(alignment: .leading, spacing: 6) {
                    Text(item.reasoning)
                    ForEach(item.policyDecisions ?? [], id: \.self) { decision in
                        Text(decision.replacingOccurrences(of: "_", with: " "))
                    }
                    ForEach(item.policyExceptions ?? [], id: \.self) { exception in
                        Label(
                            exception.replacingOccurrences(of: "_", with: " "),
                            systemImage: "exclamationmark.circle"
                        )
                        .foregroundStyle(.orange)
                    }
                }
                .font(.footnote)
                .foregroundStyle(.secondary)
                .padding(.top, 4)
            }
            .font(.footnote)

            if item.lowConfidence {
                Label("Best valid option; semantic confidence was low", systemImage: "info.circle")
                    .font(.caption)
                    .foregroundStyle(.orange)
            }

            if let alternatives = item.alternatives, !alternatives.isEmpty {
                DisclosureGroup("Alternatives") {
                    VStack(alignment: .leading, spacing: 12) {
                        ForEach(alternatives) { alternative in
                            VStack(alignment: .leading, spacing: 4) {
                                HStack(alignment: .firstTextBaseline) {
                                    Text(alternative.title)
                                        .font(.subheadline.weight(.semibold))
                                    Spacer()
                                    Text(alternative.totalPriceCents.currencyAUD)
                                }
                                Text("\(alternative.packCount) pack(s) · \(alternative.policyComparison)")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)

                                if preferredCandidateId == alternative.candidateId {
                                    Label("Saved for next time", systemImage: "checkmark")
                                        .font(.caption.weight(.semibold))
                                        .foregroundStyle(.green)
                                } else if item.canPrefer == true {
                                    Button("Prefer next time") {
                                        selectedAlternative = alternative
                                    }
                                    .buttonStyle(.bordered)
                                    .disabled(appState.isWorking)
                                }
                            }
                            .accessibilityElement(children: .contain)
                        }
                    }
                    .padding(.top, 6)
                }
                .font(.footnote)
            }

            DisclosureGroup("Recipe sources", isExpanded: $showsSources) {
                VStack(alignment: .leading, spacing: 6) {
                    ForEach(
                        Array(item.sources.enumerated()),
                        id: \.offset
                    ) { _, source in
                        Text("\(source.mealTitle): \(source.ingredientName)")
                            .font(.caption)
                    }
                }
                .padding(.top, 4)
            }
            .font(.footnote)
        }
        .padding(.vertical, 4)
        .confirmationDialog(
            "Prefer this product next time?",
            isPresented: Binding(
                get: { selectedAlternative != nil },
                set: { if !$0 { selectedAlternative = nil } }
            ),
            titleVisibility: .visible
        ) {
            Button("Prefer next time") {
                guard let alternative = selectedAlternative else { return }
                selectedAlternative = nil
                Task {
                    if await appState.rememberProductPreference(
                        itemID: item.id,
                        candidateID: alternative.candidateId
                    ) {
                        preferredCandidateId = alternative.candidateId
                    }
                }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("This will not change the current Coles basket.")
        }
    }
}

private struct BasketRecoverySection: View {
    let basket: BasketView

    @EnvironmentObject private var appState: AppState
    @State private var confirmsBudgetOverride = false

    var body: some View {
        Section {
            if basket.attention?.kind == "budget_overrun" {
                if let target = basket.attention?.budgetTargetCents {
                    LabeledContent("Basket target", value: target.currencyAUD)
                }
                if let selected = basket.attention?.selectedSubtotalCents {
                    LabeledContent("Selected products", value: selected.currencyAUD)
                }
            } else if let count = basket.attention?.blockedRequirementCount {
                Label(
                    "\(count) required product\(count == 1 ? "" : "s") could not be sourced safely.",
                    systemImage: "cart.badge.questionmark"
                )
            }

            if let adjustment = basket.adjustment {
                ForEach(adjustment.items) { item in
                    VStack(alignment: .leading, spacing: 5) {
                        Text(item.date.map { "\($0) · \(item.kind?.capitalized ?? "Meal")" }
                            ?? (item.kind?.capitalized ?? "Meal"))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        if let previous = item.previousTitle {
                            Text(previous)
                                .strikethrough()
                                .foregroundStyle(.secondary)
                        }
                        Text(item.replacementTitle)
                            .font(.headline)
                        if let summary = item.replacementSummary {
                            Text(summary)
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .padding(.vertical, 3)
                    .accessibilityElement(children: .combine)
                }
            }

            if basket.attention?.kind == "budget_overrun" {
                if basket.can.overrideBudget == true {
                    Button("Use this basket") {
                        confirmsBudgetOverride = true
                    }
                    .disabled(appState.isWorking)
                } else if basket.connection?.ownedByCurrentUser == false {
                    Label(
                        "The Coles account owner must accept this basket over the target.",
                        systemImage: "person.crop.circle.badge.exclamationmark"
                    )
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                }
            }

            if let plan = appState.planSummary(id: basket.plan.id) {
                NavigationLink {
                    MealPlanDetailView(plan: plan)
                } label: {
                    Label(
                        basket.attention?.kind == "budget_overrun"
                            ? "Review cheaper plan"
                            : "Review revised plan",
                        systemImage: "list.bullet.clipboard"
                    )
                }
            }
        } header: {
            Text("One decision needed")
        } footer: {
            Text("Chef has not changed the approved plan or the existing Coles basket. Reapproval accepts the complete displayed diff once.")
        }
        .confirmationDialog(
            "Prepare this basket above the target?",
            isPresented: $confirmsBudgetOverride,
            titleVisibility: .visible
        ) {
            Button("Use this basket") {
                Task { _ = await appState.overrideBasketBudget() }
            }
            Button("Review cheaper plan", role: .cancel) {}
        } message: {
            Text("This permits reversible basket preparation only. Checkout and payment remain in Coles.")
        }
    }
}

private struct BasketReviewView: View {
    @EnvironmentObject private var appState: AppState
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    if let session = appState.liveSession {
                        BrowserbaseLiveView(url: session.liveViewUrl)
                            .frame(minHeight: 500)
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                            .overlay {
                                RoundedRectangle(cornerRadius: 12)
                                    .stroke(.quaternary)
                            }
                            .privacySensitive()

                        EphemeralLiveInputRelay()
                    } else {
                        ContentUnavailableView(
                            "Review session ended",
                            systemImage: "safari",
                            description: Text("Return to the basket and open a new review session.")
                        )
                    }
                }
                .padding()
            }
            .navigationTitle("Review in Coles")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") {
                        Task {
                            await appState.releaseLiveView()
                            dismiss()
                        }
                    }
                }
            }
        }
    }
}
