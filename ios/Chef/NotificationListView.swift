import ChefAPI
import SwiftUI

struct NotificationListView: View {
    @EnvironmentObject private var appState: AppState
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List(appState.session?.notifications.items ?? []) { notification in
                Button {
                    Task {
                        await appState.markNotificationRead(notification)
                    }
                } label: {
                    VStack(alignment: .leading, spacing: 5) {
                        HStack {
                            Text(notification.title)
                                .font(.headline)
                            if notification.readAt == nil {
                                Circle()
                                    .fill(.tint)
                                    .frame(width: 7, height: 7)
                                    .accessibilityHidden(true)
                            }
                        }
                        Text(notification.message)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.leading)
                    }
                    .padding(.vertical, 3)
                }
                .buttonStyle(.plain)
                .accessibilityHint("Marks this notification as read")
            }
            .overlay {
                if appState.session?.notifications.items.isEmpty != false {
                    ContentUnavailableView(
                        "No basket notifications",
                        systemImage: "bell",
                        description: Text("Chef will show basket results and attention states here.")
                    )
                }
            }
            .navigationTitle("Notifications")
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") {
                        dismiss()
                    }
                }
            }
        }
    }
}
