import ChefAPI
import Foundation
import UIKit

enum LiveSessionPurpose: Equatable {
    case authentication
    case review
}

@MainActor
final class AppState: ObservableObject {
    @Published var serverAddress: String
    @Published private(set) var session: SessionResponse?
    @Published private(set) var workspace: MealPlanWorkspace?
    @Published private(set) var basket: BasketView?
    @Published private(set) var purchasePolicy: RetailerPurchasePolicyEnvelope?
    @Published private(set) var liveSession: LiveSession?
    @Published private(set) var liveSessionPurpose: LiveSessionPurpose?
    @Published private(set) var liveConsent: ConsentDisclosure?
    @Published private(set) var isRestoringSession = true
    @Published private(set) var isWorking = false
    @Published private(set) var isRelayingInput = false
    @Published private(set) var requiresTwoFactor = false
    @Published var errorMessage: String?

    private let tokenStore: any TokenStoring
    private let clientFactory: @Sendable (String) throws -> ChefAPIClient
    private let usesUITestFixture: Bool
    private var token: String?
    private var activePlanID: Int?
    private var liveConnectionID: Int?
    private var liveSessionAttemptID: UUID?
    private var pollingTask: Task<Void, Never>?

    init(
        tokenStore: any TokenStoring = KeychainTokenStore(),
        clientFactory: @escaping @Sendable (String) throws -> ChefAPIClient = { address in
            ChefAPIClient(baseURL: try ChefAPIClient.validatedBaseURL(address))
        }
    ) {
        self.tokenStore = tokenStore
        self.clientFactory = clientFactory
#if DEBUG
        usesUITestFixture = ProcessInfo.processInfo.arguments.contains("--ui-testing-m51")
#else
        usesUITestFixture = false
#endif
        serverAddress = UserDefaults.standard.string(forKey: "chef.server-address")
            ?? "http://127.0.0.1:8000"

#if DEBUG
        if usesUITestFixture {
            let fixture = UITestFixture.load(arguments: ProcessInfo.processInfo.arguments)
            token = "ui-test-token"
            session = fixture.session
            workspace = fixture.workspace
            basket = fixture.basket
            purchasePolicy = fixture.purchasePolicy
            activePlanID = fixture.workspace.plan.id
            isRestoringSession = false
            return
        }
#endif

        token = tokenStore.load()

        guard token != nil else {
            isRestoringSession = false
            return
        }

        Task { [weak self] in
            await self?.restoreSession()
        }
    }

    var isAuthenticated: Bool {
        token != nil && session != nil
    }

    func signIn(
        email: String,
        password: String,
        twoFactorCode: String?
    ) async {
        isWorking = true
        errorMessage = nil

        defer {
            isWorking = false
        }

        do {
            let client = try makeClient()
            let response = try await client.authenticate(
                AuthenticationRequest(
                    email: email,
                    password: password,
                    deviceName: UIDevice.current.name,
                    code: twoFactorCode?.nilIfBlank
                )
            )
            do {
                let loadedSession = try await client.loadSession(token: response.token)
                try tokenStore.save(response.token)
                token = response.token
                session = loadedSession
                requiresTwoFactor = false
                UserDefaults.standard.set(serverAddress, forKey: "chef.server-address")
            } catch {
                _ = try? await client.revokeCurrentToken(token: response.token)
                tokenStore.delete()
                token = nil
                session = nil

                throw error
            }
        } catch let error as ChefAPIError {
            if case .twoFactorRequired = error {
                requiresTwoFactor = true
                errorMessage = nil
            } else {
                errorMessage = error.localizedDescription
            }
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func signOut() async {
        stopPolling()

        if let token, let client = try? makeClient() {
            _ = try? await client.revokeCurrentToken(token: token)
        }

        tokenStore.delete()
        token = nil
        session = nil
        workspace = nil
        basket = nil
        purchasePolicy = nil
        liveSession = nil
        liveSessionPurpose = nil
        liveConnectionID = nil
        liveConsent = nil
        requiresTwoFactor = false
        errorMessage = nil
    }

    func refreshSession() async {
        if usesUITestFixture {
            return
        }

        guard let token else {
            return
        }

        do {
            session = try await makeClient().loadSession(token: token)
        } catch {
            handle(error)
        }
    }

    func openPlan(_ plan: MealPlanSummary) async {
        activePlanID = plan.id
        if !usesUITestFixture {
            basket = nil
        }
        await refreshWorkspace(planID: plan.id, showActivity: true)
    }

    func approveCurrentPlan() async {
        guard !isWorking, let planID = activePlanID, let token else {
            return
        }

        if usesUITestFixture {
            announce("Plan approved. Chef is preparing the grocery plan.")
            return
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            let response = try await makeClient().approvePlan(planID: planID, token: token)
            workspace = response.workspace
            announce("Plan approved. Chef is preparing the grocery plan.")
            resumePollingIfNeeded()
        } catch {
            handle(error)
        }
    }

    func startColesConnection() async {
        guard let token else {
            return
        }

        isWorking = true
        errorMessage = nil
        let attemptID = UUID()
        liveSessionAttemptID = attemptID
        defer { isWorking = false }

        do {
            let client = try makeClient()
            let response = try await client.startRetailerConnection(token: token)

            guard liveSessionAttemptID == attemptID else {
                _ = try? await client.releaseLiveSession(
                    connectionID: response.connection.id,
                    token: token
                )
                return
            }

            liveSession = response.session
            liveSessionPurpose = .authentication
            liveConnectionID = response.connection.id
            liveConsent = response.consent
        } catch {
            handle(error)
        }
    }

    func verifyColesConnection(consentAccepted: Bool) async {
        guard
            consentAccepted,
            let connectionID = liveConnectionID,
            let disclosureVersion = liveConsent?.version,
            let token
        else {
            errorMessage = "Accept the standing-consent disclosure before continuing."
            return
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            _ = try await makeClient().verifyRetailerConnection(
                connectionID: connectionID,
                disclosureVersion: disclosureVersion,
                token: token
            )
            closeLiveView()

            if let activePlanID {
                await refreshWorkspace(planID: activePlanID, showActivity: false)
            }
        } catch {
            handle(error)
        }
    }

    func relayText(_ text: String) async {
        await relay(.text(text))
    }

    func relayKey(_ key: String) async {
        await relay(.key(key))
    }

    func openBasket(runID: Int) async {
        if usesUITestFixture {
            return
        }

        guard let token else {
            return
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            basket = try await makeClient().loadBasket(runID: runID, token: token).basket
        } catch {
            handle(error)
        }
    }

    func retryBasket() async {
        guard !isWorking, let runID = basket?.id, let token else {
            return
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            _ = try await makeClient().retryBasket(runID: runID, token: token)
            basket = try await makeClient().loadBasket(runID: runID, token: token).basket

            if let activePlanID {
                await refreshWorkspace(planID: activePlanID, showActivity: false)
            }
        } catch {
            handle(error)
        }
    }

    func restoreBasket() async {
        guard !isWorking, let runID = basket?.id, let token else {
            return
        }

        if usesUITestFixture {
            announce("Previous basket restoration started.")
            return
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            _ = try await makeClient().restoreBasket(runID: runID, token: token)
            basket = try await makeClient().loadBasket(runID: runID, token: token).basket
            announce("Previous basket restoration started.")

            if let activePlanID {
                await refreshWorkspace(planID: activePlanID, showActivity: false)
            }
        } catch {
            handle(error)
        }
    }

    func startBasketReview() async {
        guard
            !isWorking,
            let basket,
            let connectionID = basket.connection?.id,
            let token
        else {
            return
        }

        isWorking = true
        errorMessage = nil
        let attemptID = UUID()
        liveSessionAttemptID = attemptID
        defer { isWorking = false }

        do {
            let client = try makeClient()
            let response = try await client.startBasketReview(
                runID: basket.id,
                token: token
            )

            guard liveSessionAttemptID == attemptID else {
                _ = try? await client.releaseLiveSession(
                    connectionID: connectionID,
                    token: token
                )
                return
            }

            liveSession = response.session
            liveSessionPurpose = .review
            liveConnectionID = connectionID
            liveConsent = nil
        } catch {
            handle(error)
        }
    }

    func loadGrocerySettings() async {
        if usesUITestFixture {
            return
        }

        guard let token else {
            return
        }

        do {
            purchasePolicy = try await makeClient().loadRetailerPurchasePolicy(token: token)
        } catch {
            handle(error)
        }
    }

    func updateGroceryPolicy(_ policy: RetailerPurchasePolicyRequest) async -> Bool {
        guard !isWorking, let token else {
            return false
        }

        if usesUITestFixture {
            announce("Household grocery policy saved.")
            return true
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            purchasePolicy = try await makeClient().updateRetailerPurchasePolicy(
                policy,
                token: token
            )
            announce("Household grocery policy saved.")
            return true
        } catch {
            handle(error)
            return false
        }
    }

    func updatePlanBasketTarget(_ basketTargetCents: Int?) async -> Bool {
        guard !isWorking, let planID = activePlanID, let token else {
            return false
        }

        if usesUITestFixture {
            announce("Plan basket target saved.")
            return true
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            _ = try await makeClient().updateMealPlanPurchasePreference(
                planID: planID,
                basketTargetCents: basketTargetCents,
                token: token
            )
            await refreshWorkspace(planID: planID, showActivity: false)
            announce("Plan basket target saved.")
            return true
        } catch {
            handle(error)
            return false
        }
    }

    func rememberProductPreference(itemID: Int, candidateID: Int) async -> Bool {
        guard !isWorking, let runID = basket?.id, let token else {
            return false
        }

        if usesUITestFixture {
            announce("Product preference saved for next time.")
            return true
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            _ = try await makeClient().rememberProductPreference(
                runID: runID,
                itemID: itemID,
                candidateID: candidateID,
                token: token
            )
            basket = try await makeClient().loadBasket(runID: runID, token: token).basket
            purchasePolicy = try? await makeClient().loadRetailerPurchasePolicy(token: token)
            announce("Product preference saved for next time.")
            return true
        } catch {
            handle(error)
            return false
        }
    }

    func revokeProductPreference(_ preferenceID: Int) async -> Bool {
        guard !isWorking, let token else {
            return false
        }

        if usesUITestFixture {
            announce("Product preference removed.")
            return true
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            _ = try await makeClient().revokeProductPreference(
                preferenceID: preferenceID,
                token: token
            )
            purchasePolicy = try await makeClient().loadRetailerPurchasePolicy(token: token)
            announce("Product preference removed.")
            return true
        } catch {
            handle(error)
            return false
        }
    }

    func overrideBasketBudget() async -> Bool {
        guard !isWorking, let runID = basket?.id, let token else {
            return false
        }

        if usesUITestFixture {
            announce("Basket target exception accepted. Preparation resumed.")
            return true
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            _ = try await makeClient().overrideBasketBudget(runID: runID, token: token)
            basket = try await makeClient().loadBasket(runID: runID, token: token).basket

            if let activePlanID {
                await refreshWorkspace(planID: activePlanID, showActivity: false)
            }
            resumePollingIfNeeded()
            announce("Basket target exception accepted. Preparation resumed.")
            return true
        } catch {
            handle(error)
            return false
        }
    }

    func planSummary(id: Int) -> MealPlanSummary? {
        session?.plans.first(where: { $0.id == id })
    }

    func disconnectColes() async {
        guard
            let connectionID = workspace?.groceryPreparation.connection?.id,
            let token
        else {
            return
        }

        isWorking = true
        errorMessage = nil
        defer { isWorking = false }

        do {
            _ = try await makeClient().disconnectRetailerConnection(
                connectionID: connectionID,
                token: token
            )

            if let activePlanID {
                await refreshWorkspace(planID: activePlanID, showActivity: false)
            }
        } catch {
            handle(error)
        }
    }

    func markNotificationRead(_ notification: ChefNotification) async {
        guard let token else {
            return
        }

        do {
            _ = try await makeClient().markNotificationRead(
                notificationID: notification.id,
                token: token
            )
            await refreshSession()
        } catch {
            handle(error)
        }
    }

    func closeLiveView() {
        liveSessionAttemptID = nil
        liveSession = nil
        liveSessionPurpose = nil
        liveConnectionID = nil
        liveConsent = nil
    }

    func releaseLiveView() async {
        let connectionID = liveConnectionID
        closeLiveView()

        guard let connectionID, let token else {
            return
        }

        do {
            _ = try await makeClient().releaseLiveSession(
                connectionID: connectionID,
                token: token
            )
        } catch {
            handle(error)
        }
    }

    func clearError() {
        errorMessage = nil
    }

    func resumePollingIfNeeded() {
        guard
            let planID = activePlanID,
            workspace?.groceryPreparation.run?.polling == true
        else {
            stopPolling()
            return
        }

        startPolling(planID: planID)
    }

    func stopPolling() {
        pollingTask?.cancel()
        pollingTask = nil
    }

    private func restoreSession() async {
        defer { isRestoringSession = false }
        await refreshSession()
    }

    private func refreshWorkspace(planID: Int, showActivity: Bool) async {
        if usesUITestFixture {
            return
        }

        guard let token else {
            return
        }

        if showActivity {
            isWorking = true
        }
        defer {
            if showActivity {
                isWorking = false
            }
        }

        do {
            let wasPolling = workspace?.groceryPreparation.run?.polling == true
            let response = try await makeClient().loadWorkspace(planID: planID, token: token)
            workspace = response.workspace

            if response.workspace.groceryPreparation.run?.polling == true {
                startPolling(planID: planID)
            } else {
                if
                    wasPolling,
                    let runID = response.workspace.groceryPreparation.run?.id,
                    basket?.id == runID
                {
                    await refreshBasketDuringPolling(runID: runID)
                }

                if wasPolling {
                    await refreshSession()
                }

                stopPolling()
            }
        } catch {
            handle(error)
        }
    }

    private func relay(_ input: LiveInput) async {
        guard
            let connectionID = liveConnectionID,
            let token
        else {
            errorMessage = "Open a new Coles Live View before entering text."
            return
        }

        isRelayingInput = true
        errorMessage = nil
        defer { isRelayingInput = false }

        do {
            _ = try await makeClient().relayLiveInput(
                connectionID: connectionID,
                input: input,
                token: token
            )
        } catch {
            handle(error)
        }
    }

    private func startPolling(planID: Int) {
        guard pollingTask == nil else {
            return
        }

        pollingTask = Task { [weak self] in
            defer {
                self?.pollingTask = nil
            }

            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(3))

                guard !Task.isCancelled, let self else {
                    return
                }

                await self.refreshWorkspace(planID: planID, showActivity: false)

                guard self.workspace?.groceryPreparation.run?.polling == true else {
                    return
                }

                if let runID = self.basket?.id {
                    await self.refreshBasketDuringPolling(runID: runID)
                }
            }
        }
    }

    private func refreshBasketDuringPolling(runID: Int) async {
        guard let token else {
            return
        }

        do {
            let previousStatus = basket?.status
            let refreshedBasket = try await makeClient().loadBasket(runID: runID, token: token).basket
            basket = refreshedBasket

            if previousStatus != nil, previousStatus != refreshedBasket.status {
                announce(GroceryStatusPresentation.make(status: refreshedBasket.status).title)
            }
        } catch {
            handle(error)
        }
    }

    private func announce(_ message: String) {
        UIAccessibility.post(notification: .announcement, argument: message)
    }

    private func makeClient() throws -> ChefAPIClient {
        try clientFactory(serverAddress)
    }

    private func handle(_ error: Error) {
        if case ChefAPIError.unauthorized = error {
            tokenStore.delete()
            token = nil
            session = nil
            workspace = nil
            basket = nil
        }

        errorMessage = error.localizedDescription
    }
}

private extension String {
    var nilIfBlank: String? {
        let trimmed = trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmed.isEmpty ? nil : trimmed
    }
}
