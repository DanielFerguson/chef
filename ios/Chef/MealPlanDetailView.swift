import ChefAPI
import SwiftUI

struct MealPlanDetailView: View {
    let plan: MealPlanSummary

    @EnvironmentObject private var appState: AppState
    @State private var showsConnection = false
    @State private var showsBasketTargetEditor = false
    @State private var confirmsApproval = false

    var body: some View {
        List {
            Section {
                LabeledContent("Starts", value: plan.startsOn)
                LabeledContent("Ends", value: plan.endsOn)
                LabeledContent("Revision", value: "\(plan.revision)")
            }

            if let workspace = matchingWorkspace {
                if shouldShowApproval(for: workspace) {
                    if let brief = workspace.approvalBrief {
                        ApprovalBriefSections(
                            brief: brief,
                            editBasketTarget: { showsBasketTargetEditor = true }
                        )
                    }

                    Section {
                        Button {
                            confirmsApproval = true
                        } label: {
                            Label(
                                workspace.groceryPreparation.approvalLabel ?? "Approve meal plan",
                                systemImage: "checkmark.circle"
                            )
                        }
                        .disabled(appState.isWorking || workspace.approvalBrief == nil)
                    } footer: {
                        Text(workspace.approvalBrief?.groceryPreparation.effect
                            ?? "Approval starts the atomic recipe batch. Coles basket preparation remains reversible and never checks out.")
                    }
                }

                if workspace.groceryPreparation.enabled {
                    Section("Grocery preparation") {
                        GroceryPreparationSummary(
                            preparation: workspace.groceryPreparation,
                            connect: { showsConnection = true }
                        )

                        if let run = workspace.groceryPreparation.run {
                            NavigationLink {
                                BasketScreen(runID: run.id)
                            } label: {
                                Label("Open basket", systemImage: "basket")
                            }
                        }
                    }

                    if workspace.groceryPreparation.connection?.ownedByCurrentUser == true {
                        Section {
                            Button("Disconnect Coles", role: .destructive) {
                                Task {
                                    await appState.disconnectColes()
                                }
                            }
                            .disabled(appState.isWorking)
                        } footer: {
                            Text("Disconnecting revokes standing consent and deletes the hosted Browserbase Context.")
                        }
                    }
                }
            } else {
                Section {
                    HStack {
                        Spacer()
                        ProgressView("Loading plan")
                        Spacer()
                    }
                }
            }
        }
        .navigationTitle(plan.title)
        .navigationBarTitleDisplayMode(.inline)
        .task(id: plan.id) {
            await appState.openPlan(plan)
        }
        .onDisappear {
            appState.stopPolling()
        }
        .sheet(isPresented: $showsConnection, onDismiss: {
            if appState.liveSessionPurpose == .authentication {
                Task {
                    await appState.releaseLiveView()
                }
            }
        }) {
            ColesConnectionView()
                .environmentObject(appState)
        }
        .sheet(isPresented: $showsBasketTargetEditor) {
            PlanBasketTargetEditor(
                currentTargetCents: matchingWorkspace?.approvalBrief?.purchasePolicy.basketTargetCents
            )
            .environmentObject(appState)
        }
        .confirmationDialog(
            "Approve this complete meal plan?",
            isPresented: $confirmsApproval,
            titleVisibility: .visible
        ) {
            Button("Approve plan") {
                Task { await appState.approveCurrentPlan() }
            }
            Button("Keep reviewing", role: .cancel) {}
        } message: {
            Text(matchingWorkspace?.approvalBrief?.groceryPreparation.effect
                ?? "Chef will prepare the approved recipes.")
        }
    }

    private var matchingWorkspace: MealPlanWorkspace? {
        guard appState.workspace?.plan.id == plan.id else {
            return nil
        }

        return appState.workspace
    }

    private func shouldShowApproval(for workspace: MealPlanWorkspace) -> Bool {
        workspace.plan.planningConfirmedAt == nil
            || workspace.groceryPreparation.run?.publicState == "plan_review_required"
    }
}

private struct ApprovalBriefSections: View {
    let brief: MealPlanApprovalBrief
    let editBasketTarget: () -> Void

    var body: some View {
        Section("Approval summary") {
            LabeledContent("Meals", value: "\(brief.mealCount)")
            LabeledContent("Estimated cooking time", value: "\(brief.estimatedMinutes) minutes")
            LabeledContent("Estimated meal cost", value: brief.estimatedCostCents.currencyAUD)
            Button {
                editBasketTarget()
            } label: {
                LabeledContent(
                    "Basket target",
                    value: brief.purchasePolicy.basketTargetCents?.currencyAUD ?? "No target"
                )
            }
            .accessibilityHint("Edit the basket target for this plan")
        }

        Section("Effective meals") {
            ForEach(brief.meals) { meal in
                VStack(alignment: .leading, spacing: 6) {
                    HStack(alignment: .firstTextBaseline) {
                        Text(meal.title ?? "Open meal")
                            .font(.headline)
                        Spacer()
                        if meal.isReplacement {
                            Text("Replacement")
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(.tint)
                        }
                    }
                    Text("\(meal.date) · \(meal.kind.capitalized)")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    Text(meal.participants.map {
                        "\($0.name) (\($0.servings.value.formatted()))"
                    }.joined(separator: ", "))
                    .font(.subheadline)

                    if meal.participantDefault.provisional {
                        Label(
                            meal.participantDefault.sourceLabel.map { "Suggested from \($0)" }
                                ?? "Suggested from the current household",
                            systemImage: "person.badge.clock"
                        )
                        .font(.caption)
                        .foregroundStyle(.orange)
                    }
                }
                .padding(.vertical, 3)
                .accessibilityElement(children: .combine)
            }
        }

        if !brief.safety.constraints.isEmpty {
            Section("Explicit safety constraints") {
                ForEach(brief.safety.constraints) { constraint in
                    VStack(alignment: .leading, spacing: 3) {
                        Text(constraint.subject)
                            .font(.headline)
                        Text([constraint.person, constraint.details]
                            .compactMap { $0 }
                            .joined(separator: " · "))
                            .foregroundStyle(.secondary)
                    }
                }
                Text("Chef does not infer allergies or safety constraints.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }
        }

        Section("Grocery policy") {
            LabeledContent("Home brand", value: brief.purchasePolicy.homeBrandPreference.capitalized)
            LabeledContent("Bulk packs", value: brief.purchasePolicy.bulkPreference.capitalized)
            LabeledContent(
                "Organic",
                value: brief.purchasePolicy.organicPreference
                    .replacingOccurrences(of: "_", with: " ")
                    .capitalized
            )
            if !brief.purchasePolicy.preferredBrands.isEmpty {
                LabeledContent(
                    "Preferred brands",
                    value: brief.purchasePolicy.preferredBrands.joined(separator: ", ")
                )
            }
        }
    }
}

private struct PlanBasketTargetEditor: View {
    let currentTargetCents: Int?

    @EnvironmentObject private var appState: AppState
    @Environment(\.dismiss) private var dismiss
    @State private var target = ""

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("Target in dollars", text: $target)
                        .keyboardType(.decimalPad)
                } footer: {
                    Text("Leave blank for no plan-specific target. Chef never bypasses a target without the Coles account owner choosing to continue.")
                }
            }
            .navigationTitle("Plan basket target")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        Task {
                            if await appState.updatePlanBasketTarget(parsedTarget.cents) {
                                dismiss()
                            }
                        }
                    }
                    .disabled(appState.isWorking || parsedTarget == .invalid)
                }
            }
            .onAppear {
                target = currentTargetCents.map {
                    String(format: "%.2f", Double($0) / 100)
                } ?? ""
            }
        }
    }

    private var parsedTarget: CurrencyInput { CurrencyInput(target) }
}

private struct GroceryPreparationSummary: View {
    let preparation: GroceryPreparation
    let connect: () -> Void

    var body: some View {
        if let run = preparation.run {
            let presentation = GroceryStatusPresentation.make(status: run.status)

            VStack(alignment: .leading, spacing: 9) {
                HStack(alignment: .firstTextBaseline) {
                    if presentation.isActive {
                        ProgressView()
                            .controlSize(.small)
                    } else {
                        Image(systemName: presentation.needsAttention
                            ? "exclamationmark.circle"
                            : "checkmark.circle")
                            .foregroundStyle(presentation.needsAttention ? .orange : .green)
                    }

                    Text(presentation.title)
                        .font(.headline)
                }

                Text(run.failureMessage ?? presentation.detail)
                    .foregroundStyle(.secondary)

                if let total = run.retailerTotalCents ?? run.chefSubtotalCents {
                    Text(total.currencyAUD)
                        .font(.title3.weight(.semibold))
                        .accessibilityLabel("Estimated total \(total.currencyAUD)")
                }

                if run.status == "waiting_for_connection"
                    || run.status == "reauthentication_required" {
                    Button("Connect Coles", action: connect)
                        .buttonStyle(.borderedProminent)
                }
            }
            .padding(.vertical, 5)
        } else if preparation.hasStandingConsent == true {
            Label("Coles is connected for the next approved plan", systemImage: "checkmark.shield")
                .foregroundStyle(.secondary)
        } else {
            VStack(alignment: .leading, spacing: 8) {
                Text("Coles connects after your first approval.")
                    .foregroundStyle(.secondary)
                Button("Connect Coles", action: connect)
                    .buttonStyle(.borderedProminent)
                    .disabled(preparation.canConnect == false)
            }
        }
    }
}
