import ChefAPI
import SwiftUI

struct PlanListView: View {
    @EnvironmentObject private var appState: AppState
    @State private var showsNotifications = false
    @State private var showsGrocerySettings = false

    var body: some View {
        NavigationStack {
            List {
                if let household = appState.session?.household {
                    Section {
                        LabeledContent("Family", value: household.name)
                        LabeledContent("People", value: "\(household.people.count)")
                    }
                }

                Section("Meal plans") {
                    ForEach(appState.session?.plans ?? []) { plan in
                        NavigationLink(value: plan) {
                            VStack(alignment: .leading, spacing: 5) {
                                Text(plan.title)
                                    .font(.headline)
                                Text("\(plan.startsOn) – \(plan.endsOn)")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                                Text(plan.planningConfirmedAt == nil ? "Draft" : "Approved")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            .padding(.vertical, 3)
                        }
                    }
                }
            }
            .navigationTitle("Chef")
            .navigationDestination(for: MealPlanSummary.self) { plan in
                MealPlanDetailView(plan: plan)
            }
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Sign out", systemImage: "rectangle.portrait.and.arrow.right") {
                        Task {
                            await appState.signOut()
                        }
                    }
                }

                ToolbarItem(placement: .topBarTrailing) {
                    Button {
                        showsNotifications = true
                    } label: {
                        Image(systemName: appState.session?.notifications.unreadCount == 0
                            ? "bell"
                            : "bell.badge")
                    }
                    .accessibilityLabel(
                        "\(appState.session?.notifications.unreadCount ?? 0) unread notifications"
                    )
                }

                ToolbarItem(placement: .topBarTrailing) {
                    Button("Grocery settings", systemImage: "basket") {
                        showsGrocerySettings = true
                    }
                }
            }
            .refreshable {
                await appState.refreshSession()
            }
            .task {
                await appState.refreshSession()
            }
            .sheet(isPresented: $showsNotifications) {
                NotificationListView()
                    .environmentObject(appState)
            }
            .sheet(isPresented: $showsGrocerySettings) {
                GrocerySettingsView()
                    .environmentObject(appState)
            }
        }
    }
}
