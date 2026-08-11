import ChefAPI
import SwiftUI

struct GrocerySettingsView: View {
    @EnvironmentObject private var appState: AppState
    @Environment(\.dismiss) private var dismiss

    @State private var homeBrandPreference = "allow"
    @State private var bulkPreference = "avoid"
    @State private var organicPreference = "no_preference"
    @State private var preferredBrands = ""
    @State private var basketTarget = ""
    @State private var confirmsPreferenceRevocation: RetailerProductPreferenceView?

    var body: some View {
        NavigationStack {
            Form {
                if let settings = appState.purchasePolicy {
                    Section {
                        Picker("Home brand", selection: $homeBrandPreference) {
                            Text("Allow").tag("allow")
                            Text("Prefer").tag("prefer")
                            Text("Avoid").tag("avoid")
                        }

                        Picker("Bulk packs", selection: $bulkPreference) {
                            Text("Allow").tag("allow")
                            Text("Avoid").tag("avoid")
                        }

                        Picker("Organic", selection: $organicPreference) {
                            Text("No preference").tag("no_preference")
                            Text("Prefer").tag("prefer")
                        }

                        TextField("Preferred brands, comma separated", text: $preferredBrands)
                            .textInputAutocapitalization(.words)

                        TextField("Default basket target in dollars", text: $basketTarget)
                            .keyboardType(.decimalPad)
                            .accessibilityHint("Leave blank for no household basket target")
                    } header: {
                        Text("Household purchasing policy")
                    } footer: {
                        if settings.can.update {
                            Text("Chef applies these preferences only after hard safety and availability checks.")
                        } else {
                            Text("Only a household owner or admin can change household-wide policy.")
                        }
                    }
                    .disabled(!settings.can.update)

                    Section {
                        if settings.productPreferences.isEmpty {
                            Text("No saved product preferences yet.")
                                .foregroundStyle(.secondary)
                        } else {
                            ForEach(settings.productPreferences) { preference in
                                VStack(alignment: .leading, spacing: 4) {
                                    Text(preference.productTitle)
                                        .font(.headline)
                                    Text([preference.ingredient, preference.form]
                                        .compactMap { $0 }
                                        .joined(separator: " · "))
                                        .font(.subheadline)
                                        .foregroundStyle(.secondary)
                                    Button("Stop preferring this product", role: .destructive) {
                                        confirmsPreferenceRevocation = preference
                                    }
                                    .disabled(appState.isWorking)
                                }
                                .padding(.vertical, 4)
                            }
                        }
                    } header: {
                        Text("Saved product preferences")
                    } footer: {
                        Text("Saved choices are considered next time only and never change the current Coles basket.")
                    }
                } else {
                    Section {
                        HStack {
                            Spacer()
                            ProgressView("Loading grocery settings")
                            Spacer()
                        }
                    }
                }
            }
            .navigationTitle("Grocery settings")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { dismiss() }
                }

                if appState.purchasePolicy?.can.update == true {
                    ToolbarItem(placement: .confirmationAction) {
                        Button("Save") {
                            Task {
                                if await appState.updateGroceryPolicy(policyRequest) {
                                    dismiss()
                                }
                            }
                        }
                        .disabled(appState.isWorking || parsedBasketTarget == .invalid)
                    }
                }
            }
            .task {
                await appState.loadGrocerySettings()
                loadDraft()
            }
            .confirmationDialog(
                "Stop preferring this product?",
                isPresented: Binding(
                    get: { confirmsPreferenceRevocation != nil },
                    set: { if !$0 { confirmsPreferenceRevocation = nil } }
                ),
                titleVisibility: .visible
            ) {
                Button("Revoke preference", role: .destructive) {
                    guard let preference = confirmsPreferenceRevocation else { return }
                    confirmsPreferenceRevocation = nil
                    Task { _ = await appState.revokeProductPreference(preference.id) }
                }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Chef will choose normally next time. The current basket will not change.")
            }
        }
    }

    private var policyRequest: RetailerPurchasePolicyRequest {
        RetailerPurchasePolicyRequest(
            homeBrandPreference: homeBrandPreference,
            bulkPreference: bulkPreference,
            organicPreference: organicPreference,
            preferredBrands: preferredBrands
                .split(separator: ",")
                .map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
                .filter { !$0.isEmpty },
            defaultBasketTargetCents: parsedBasketTarget.cents
        )
    }

    private var parsedBasketTarget: CurrencyInput {
        CurrencyInput(basketTarget)
    }

    private func loadDraft() {
        guard let policy = appState.purchasePolicy?.policy else { return }
        homeBrandPreference = policy.homeBrandPreference
        bulkPreference = policy.bulkPreference
        organicPreference = policy.organicPreference
        preferredBrands = policy.preferredBrands.joined(separator: ", ")
        basketTarget = policy.defaultBasketTargetCents.map {
            String(format: "%.2f", Double($0) / 100)
        } ?? ""
    }
}

enum CurrencyInput: Equatable {
    case empty
    case valid(Int)
    case invalid

    init(_ value: String) {
        let trimmed = value.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            self = .empty
            return
        }

        guard let decimal = Decimal(string: trimmed), decimal >= 0 else {
            self = .invalid
            return
        }

        let cents = NSDecimalNumber(decimal: decimal * 100).intValue
        self = .valid(cents)
    }

    var cents: Int? {
        if case let .valid(cents) = self { return cents }
        return nil
    }
}
